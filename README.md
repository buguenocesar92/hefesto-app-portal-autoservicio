# Portal de Autoservicio de Clientes

Aplicación propia de **Hefesto Studio**: un portal donde el cliente final de
una PyME (una panaderia, un taller, una bodega) abre solicitudes de servicio,
descarga los documentos que le emitieron y revisa su historial, sin llamar por
telefono. La PyME administra sus personas, sus categorias y el estado de cada
solicitud.

> **Este proyecto es software original de Hefesto.** No es un kit de despliegue
> de una herramienta de terceros, ni una reubicacion de código ajeno. Se uso
> Laravel, Nuxt, Socialite, `firebase/php-jwt` y Authentik como librerias, que
> es lo normal: lo que se escribio aquí es el portal.

---

## Estado

**Primer corte. Código escrito, nada ejecutado.**

No se corrieron las migraciones, ni las pruebas, ni una construccion del
frontend. **No se puede decir que esto este listo para producción.**

El detalle exacto de lo que se verifico y lo que no esta en
**[docs/10-PRUEBAS.md](docs/10-PRUEBAS.md)**, que es el primer documento que
hay que leer.

---

## Lo que hay

| Carpeta | Que contiene |
|---|---|
| `api/` | Laravel: modelos, migraciones, RLS, servicios, controladores, rutas, pruebas |
| `web/` | Nuxt 4 con render en el servidor, y el BFF que reenvia las cookies |
| `deploy/` | Dockerfiles, `compose` de desarrollo y producción, Caddy |
| `docs/` | Diez documentos: arquitectura, autenticación, multi-tenancy, instalación, operación, API, despliegue, seguridad, alcance y pruebas |
| `herramientas/` | Scripts de verificación que no dependen de PHP ni de Node |

## Lo que hace

1. **Autenticación OIDC contra Authentik**, con PKCE y verificación propia del
   token de identificacion.
2. **Solicitudes de servicio**: folio propio por PyME, estados y bitacora.
3. **Documentos** que la PyME público, descargables con autorización.
4. **Historial** de lo pedido y lo entregado.
5. **Administración del tenant**: personas, categorias y estados.

## Lo que NO hace

No emite documentos tributarios, no tiene pagos, no envia correos ni SMS, no
permite subir documentos desde la interfaz y no tiene pantalla de
administración de la PyME. El detalle esta en
[docs/09-ALCANCE.md](docs/09-ALCANCE.md).

En particular: **no hay ningun conector con LibreDTE**. Su API es de tipo GET
y esta en beta; si alguna vez se integra, será un modulo aparte y verificado.

---

## La decisión que explica el resto

**El aislamiento entre Pymes no lo implementa solo la aplicación: lo impone
PostgreSQL.**

Todas las tablas de negocio llevan `tenant_id`, y hay políticas de **Row Level
Security** que impiden que una consulta devuelva filas de otra PyME. El tenant
viaja en un variable de sesión con **alcance de transacción**, y la función que
la lee **lanza excepcion** si no hay contexto: el aislamiento falla cerrado, no
abierto.

La justificacion completa de por que se eligio esto y no un esquema por
cliente esta en **[docs/03-MULTITENANCY.md](docs/03-MULTITENANCY.md)**. Es el
documento que hay que leer antes de tocar cualquier consulta.

---

## Puesta en marcha

Requiere Docker con Compose v2. No hace falta PHP, Composer, Node ni
PostgreSQL en el host.

```bash
cp .env.example .env

# Generar APP_KEY
docker compose -f deploy/docker-compose.dev.yml run --rm api php artisan key:generate

docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d

# Migraciones y datos de ejemplo (dos Pymes, para poder probar el aislamiento)
docker compose -f deploy/docker-compose.dev.yml exec api php artisan migrate --seed

# Verificacion OBLIGATORIA del aislamiento
docker compose -f deploy/docker-compose.dev.yml exec api php artisan portal:verificar-aislamiento
```

| Servicio | URL |
|---|---|
| Interfaz | http://localhost:3000 |
| API | http://localhost:8000/api/v1 |
| Authentik | http://localhost:9000 |

El detalle completo, incluida la configuración del proveedor OAuth2 en
Authentik, esta en **[docs/04-INSTALACION.md](docs/04-INSTALACION.md)**.

> Los usuarios que crea el seeder **no pueden entrar al portal**: sus
> identidades en Authentik no existen. Sirven para la base de datos y las
> pruebas, no para el flujo de acceso.

---

## Stack

| Pieza | Tecnologia |
|---|---|
| API | Laravel 13.37 / PHP 8.4 |
| Base de datos | PostgreSQL 18.6 (con Row Level Security) |
| Interfaz | Nuxt 4.6 / Node 24 |
| Cache y colas | Redis 8.8 |
| Identidad | Authentik 2025.10 (OIDC) |
| Entrada pública | Caddy 2.11 (HTTPS automático) |

Las versiones estan fijadas por tag exacto y fueron verificadas contra
Packagist, npm y Docker Hub. Falta fijarlas por digest SHA256 antes de
producción ([docs/07-DESPLIEGUE.md](docs/07-DESPLIEGUE.md)).

---

## Documentacion

Empieza por **[docs/00-INDICE.md](docs/00-INDICE.md)**.

| Documento | De que trata |
|---|---|
| [01 - Arquitectura](docs/01-ARQUITECTURA.md) | Las piezas del sistema y por que estan separadas así |
| [02 - Autenticación](docs/02-AUTENTICACION.md) | OIDC contra Authentik, y como se decide la PyME |
| [03 - Multi-tenancy](docs/03-MULTITENANCY.md) | **El central.** Aislamiento entre Pymes |
| [04 - Instalación](docs/04-INSTALACION.md) | Paso a paso para levantar el portal |
| [05 - Operación](docs/05-OPERACION.md) | Tareas del dia a dia y problemas frecuentes |
| [06 - API](docs/06-API.md) | Endpoints, convenciones, estados |
| [07 - Despliegue](docs/07-DESPLIEGUE.md) | Producción, respaldos, escalado |
| [08 - Seguridad](docs/08-SEGURIDAD.md) | Medidas aplicadas **y las que faltan** |
| [09 - Alcance](docs/09-ALCANCE.md) | Lo que hace y lo que no |
| [10 - Pruebas](docs/10-PRUEBAS.md) | **Que se ejecuto y que quedo sin probar** |

---

## Seguridad

Si encuentra un problema de seguridad, no abra un ticket público. Escriba
directamente a Hefesto Studio.

Los puntos abiertos conocidos estan listados en
[docs/08-SEGURIDAD.md](docs/08-SEGURIDAD.md), seccion "Lo que NO se hizo".
Entre los mas relevantes: las pruebas de aislamiento estan escritas pero **no
se ejecutaron**, y no hay limites de tasa en los endpoints.