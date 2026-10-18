# 07 - Despliegue

## 1. Que se despliega

`deploy/docker-compose.prod.yml` levanta nueve servicios. **Solo Caddy pública
puertos.**

| Servicio | Puerto publicado | Imagen |
|---|---|---|
| `caddy` | 80, 443 | `caddy:2.11.7-alpine` |
| `web` | ninguno | build local |
| `api` | ninguno | `${API_IMAGE}` |
| `cola` | ninguno | `${API_IMAGE}` |
| `postgres` | ninguno | `postgres:18.6` |
| `redis` | ninguno | `redis:8.8.3-alpine` |
| `authentik-server` | ninguno | `ghcr.io/goauthentik/server:2025.10` |
| `authentik-worker` | ninguno | `ghcr.io/goauthentik/server:2025.10` |
| `authentik-postgres` | ninguno | `postgres:18.6` |

`cola` usa **la misma imagen** que `api`, con distinto `command`. Es el patron
correcto: si el worker se demora, las peticiones siguen respondiendose.

---

## 2. Procedimiento de despliegue

```bash
# 1. Construir las imagenes
docker compose -f deploy/docker-compose.prod.yml build

# 2. Actualizar las variables (.env.prod, que NO se versiona)

# 3. Aplicar migraciones, una vez y de forma explicita
docker compose -f deploy/docker-compose.prod.yml run --rm api \
  php artisan migrate --force

# 4. Verificar el aislamiento ANTES de abrir el portal
docker compose -f deploy/docker-compose.prod.yml run --rm api \
  php artisan portal:verificar-aislamiento

# 5. Levantar
docker compose -f deploy/docker-compose.prod.yml up -d
```

Las migraciones **no** se corren en cada arranque (`PORTAL_CORRER_MIGRACIONES:
"false"`). Un despliegue que cambia el esquema sin que nadie lo decida es un
despliegue que puede cortar el servicio.

### Por que el paso 4 va antes del paso 5

`portal:verificar-aislamiento` falla si el rol de la base tiene `BYPASSRLS`, si
una tabla quedo sin política o si la función de contexto dejo de fallar
cerrada. Son fallos que **no se ven en la interfaz**: el portal funciona
perfectamente y muestra datos de otros clientes a quien no deberia.

Por eso es un paso aparte del despliegue y no un chequeo de arranque. En el
`entrypoint` queda un aviso (no bloqueante), para que el log lo registre, pero
el despliegue debe revisar la salida.

---

## 3. Pinning de imagenes

Todas las imagenes estan fijadas por **tag exacto** (no `latest`). Antes de
producción hay que fijarlas también por **digest SHA256**:

```bash
docker inspect --format='{{index .RepoDigests 0}}' postgres:18.6
# postgres@sha256:...
```

Y en el compose:

```yaml
image: postgres:18.6@sha256:...
```

**Estado actual: esto esta pendiente.** Las imagenes de PHP y Node estan fijadas
por tag exacto; `composer:2` en el `Dockerfile` de la API **no esta fijada**
(la imagen oficial de Composer no tiene una versión que se haya podido
verificar al escribir esto). Es una tarea abierta, anotada en
[10 - Pruebas](10-PRUEBAS.md).

Las versiones de PHP, Composer, Nuxt y las librerias de PHP **si** se
verificaron contra Packagist, npm y Docker Hub antes de fijarlas.

---

## 4. Por que Authentik tiene su propia base de datos

No es estetica, es técnica:

- Authentik pide parámetros de PostgreSQL concretos (por ejemplo, sobre
  `max_connections` y el protocolo de autenticación) que no son los que necesita
  el portal.
- Mezclarlas obliga a tocar parámetros que uno de los dos necesita, y tocarlos
  "para que sirva" es como se rompen las dos cosas.
- Un respaldo de Authentik (con sus usuarios, flujos y aplicaciones) es
  independiente del respaldo del portal.
- Si Authentik se cae, el portal sigue disponible: se corta el acceso, pero la
  interfaz y los datos quedan de pie.

### Authentik 2025.10

Verificado: desde 2025.10 **Authentik elimino Redis**. Son tres servicios:
`postgresql`, `server` y `worker`. `server` y `worker` usan **la misma imagen**
con distinto `command`, y **no existe una imagen `worker` separada**.

El `compose` de este proyecto sigue exactamente esa forma.

---

## 5. Respaldos

Dos volumenes necesitan respaldo:

| Volumen | Que contiene |
|---|---|
| `postgres-datos` | Solicitudes, documentos, usuarios, sesiones, bitacora |
| `documentos` | Los archivos que la PyME público |

### Base de datos

```bash
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_dump -U portal_app portal_autoservicio | gzip > respaldo-$(date +%F).sql.gz
```

Frecuencia recomendada: diaria. Retencion: 30 dias diarios, 12 mensuales.

### Documentos

```bash
docker run --rm -v portal-autoservicio_documentos:/d -v "$PWD":/b alpine \
  tar czf /b/documentos-$(date +%F).tar.gz -C /d .
```

### Probar un respaldo

Un respaldo que nunca se restauro no es un respaldo. Al menos una vez al mes,
restaurarlo en una base vacia y verificar que el aislamiento sigue en pie:

```bash
createdb portal_prueba
gunzip -c respaldo.sql.gz | psql -d portal_prueba
php artisan portal:verificar-aislamiento   # con DB_DATABASE=portal_prueba
```

---

## 6. Escalado

La API es **sin estado**: la sesión esta en PostgreSQL y la cache en Redis. Eso
permite correr varias replicas sin cambios.

```yaml
api:
  deploy:
    replicas: 3
```

Lo que **no** escala bien, y hay que saber antes de intentarlo:

- **PostgreSQL con Row Level Security**: aguanta bien hasta cientos de Pymes.
  Si algun dia hicieran falta shards, la RLS habria que volver a evaluarse.
- **El volumen de documentos**: es local. Con varias replicas hay que moverlo a
  un almacen de objetos (S3) o a un volumen compartido. En el volumen
  compartido, la API sigue sirviendo el archivo por el endpoint (nunca como
  contenido estático), así que la RLS sigue aplicando.
- **El worker de colas**: `cola` debe correr **una sola vez**. Con varias
  replicas, el trabajo se repartiria, pero el consumo de memoria sube sin
  ganancia.

---

## 7. Observabilidad

| Que | Donde |
|---|---|
| Errores de la aplicación | `docker compose logs api` |
| Hechos de seguridad previos al login | Canal `portal_seguridad`, JSON por `stderr` |
| Trafico HTTP | Access log de Caddy, en JSON, con rotacion |
| Bitacora de escrituras | Tabla `bitacora_accesos`, por PyME |
| Estado del aislamiento | `php artisan portal:verificar-aislamiento` |

Las escrituras quedan en `bitacora_accesos` (quien hizo que, desde donde). Los
hechos que ocurren **antes** del login no pueden ir ahi porque no tienen
tenant, y la RLS lo impediria: van al canal `portal_seguridad`.

---

## 8. Actualizaciones

1. Revisar las notas de la versión nueva de Laravel, PHP, PostgreSQL y
   Authentik.
2. Actualizar tags en el compose y en los `Dockerfile`.
3. Construir y correr las pruebas en un entorno con los tags nuevos.
4. Respaldar la base.
5. Desplegar siguiendo la seccion 2.

Nunca saltar una versión mayor de PostgreSQL sin probar el respaldo: los
formatos de indice cambian entre versiones mayores.