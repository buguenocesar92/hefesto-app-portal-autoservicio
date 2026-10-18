# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [08](08-SEGURIDAD.md).

## 1. Que es esto

Un portal donde el cliente final de una PyME (una panaderia, un taller) abre
solicitudes de servicio, descarga los documentos que la PyME le emitio y ve
el historial, sin llamar por telefono.

La PyME que lo usa (el cliente de Hefesto) administra a sus propios usuarios,
sus categorias de servicio y el estado de cada solicitud.

---

## 2. Las piezas

```
                    +---------------------------+
   navegador  --->  |  Caddy (puerto 80/443)    |
                    +------------+--------------+
                                 |
              +------------------+------------------+
              |                                     |
     /api/**  |                            (todo lo demas)
              |                                     |
    +---------v----------+             +-------------v-----------+
    |  web  (Nuxt 4 SSR) |             |  web (Nuxt 4 SSR)     |
    |  BFF en el servidor|             |  render de paginas     |
    +---------+----------+             +-------------+-----------+
              |  reenvia cookies + X-XSRF-TOKEN                 |
    +---------v----------+                                      |
    |  api (Laravel)     |                                      |
    |  autoridad de la   |                                      |
    |  sesion            |                                      |
    +----+----+----+-----+                                      |
         |    |    |                                            |
    +----v-+ +v---+ +v----------+                               |
    |Redis  | |cola | |PostgreSQL|  <-- RLS: la base           |
    |cache  | |worker| |          |      decide el tenant        |
    +-------+ +-----+ +-----+-----+                            |
                                 |                              |
    +----------------------------v-------------+                |
    |  Authentik 2025.10 (server + worker)     |   <-- identidad  |
    |  (base de datos propia)                  |                 |
    +------------------------------------------+                |
                                 |                              |
                        +--------v---------+                    |
                        |  Postgres de     |                    |
                        |  Authentik       |                    |
                        +------------------+                    |
                            +------------------------------------+
```

Componentes:

| Componente | Tecnologia | Rol |
|---|---|---|
| `caddy` | Caddy 2.11.7 | Único punto de entrada público. HTTPS y reparto de trafico |
| `web` | Nuxt 4.6 (Node 24) | Interfaz con render en el servidor, y BFF |
| `api` | Laravel 13 / PHP 8.4 | Autoridad de la sesión y de los datos |
| `cola` | Misma imagen, otro comando | Trabajador de colas |
| `postgres` | PostgreSQL 18.6 | Datos, sesiones y **aislamiento por RLS** |
| `redis` | Redis 8.8 | Cache (incluido el JWKS de Authentik) y colas |
| `authentik` | Authentik 2025.10 | Identidad de las personas |

---

## 3. Por que hay un BFF (y no una SPA hablando directo con la API)

La sesión vive en una cookie **HttpOnly + Secure + SameSite=Lax**. Con una
cookie así:

- El JavaScript de la pagina **no** puede leerla. No hay token en
  `localStorage` que un XSS pueda robar.
- El navegador solo la envia a **su mismo origen**.

Si la SPA hablara directo con `api.hfestostudio.cl` (otro origen), la cookie
no viajaria. Habria que resolver CORS con credenciales y el token CSRF se
volveria fragil (o se tendria que guardar un token accesible desde
JavaScript, que es justamente lo que se quiere evitar).

Con el BFF, el navegador ve **una sola origen**:

1. El navegador pide `/solicitudes` a Caddy.
2. Caddy lo manda al contenedor `web`.
3. `web` pide `/api/v1/solicitudes` a `api`, reenviando la cookie.
4. `api` responde con JSON.
5. `web` renderiza la pagina (o devuelve el JSON al navegador).

El token CSRF viaja como la cabecera `X-XSRF-TOKEN`: el BFF lee la cookie
`XSRF-TOKEN` y la copia como cabecera, que es lo que Laravel acepta. El
navegador nunca ve el token.

**Costo que se acepta:** un salto extra. **Beneficio:** la sesión y el CSRF son
una sola concern y no estan repartidos en dos mecanismos.

---

## 4. Por que la sesión vive en PostgreSQL

La API corre en **varios contenedores** (y puede correr mas de uno a la vez
tras un balanceador). Con el controlador de archivos de Laravel, el usuario
pierde la sesión cada vez que otra replica lo atiende: es un sintoma
intermitente, dificil de reproducir y de explicar.

Por eso `SESSION_DRIVER=database` y la tabla existe como migración propia
(`2026_01_05_000010_crear_tabla_sessions.php`).

El costo es una escritura extra por peticion (el serialize de la sesión). Para
un portal de este volumen es irrelevante.

---

## 5. Como circula un dato

Ejemplo: el cliente abre una solicitud.

1. `GET /solicitudes/nueva` llega a `web`, que pide
   `GET /api/v1/categorias` por el BFF.
2. `api` corre la peticion **dentro de una transacción** abierta por
   `ResolverTenant`, con `app.tenant_id` fijado.
3. La RLR deja pasar solo las categorias de esa PyME. La peticion responde.
4. El formulario se envia por `POST /api/v1/solicitudes`.
5. `api` valida, genera el folio con el contador bloqueado de esa PyME, inserta
   la solicitud y su primer evento.
6. Todo eso ocurre en **la misma transacción**: o se guarda entero, o nada.
7. `AuditarAcceso` escribe la linea de bitacora al terminar.

---

## 6. Estructura del repositorio

```
portal-autoservicio/
  api/          Laravel: modelos, migraciones, servicios, controladores
  web/          Nuxt: paginas, componentes y el BFF (server/api)
  deploy/       Dockerfiles, compose de desarrollo y produccion, Caddy
  docs/         Esta documentacion
  herramientas/ Scripts de verificacion que no dependen de PHP ni de Node
```

---

## 7. Decisiones de tecnologia, en una linea cada una

| Decisión | Motivo |
|---|---|
| Laravel + PostgreSQL | Ecosistema maduro y RLS, que es el mecanismo de aislamiento |
| Nuxt SSR y no SPA | El BFF necesita ejecutarse en el servidor |
| Caddy | HTTPS automático y un solo proceso de entrada |
| Redis | Cache del JWKS (que rotar es raro) y colas |
| Authentik | Identidad autoalojada, sin depender de un SaaS con los datos de los clientes |
| Sesión en base | Multiples replicas |

---

## 8. Lo que este primer corte NO hace

- No hay pagos.
- No hay emision de documentos tributarios.
- No hay notificaciones por correo ni SMS.
- No hay carga de documentos desde la interfaz (la PyME los deja en el volumen).

Ver [09 - Alcance](09-ALCANCE.md) para el detalle.