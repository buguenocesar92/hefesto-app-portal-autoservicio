# 04 - Instalación

## 0. Antes de empezar

Requisitos del servidor:

- Docker con Compose v2 (`docker compose version`)
- 2 nucleos y 2 GB de RAM minimos (4 GB recomendado)
- Un dominio que apunte al servidor, si se quiere usar HTTPS

No hace falta PHP, Composer, Node ni PostgreSQL instalados en el host: todo
corre dentro de contenedores.

---

## 1. Crear la base de datos del portal

El rol de la aplicación **no puede ser superusuario ni tener `BYPASSRLS`**.
Con cualquiera de las dos cosas, PostgreSQL ignora las políticas de Row Level
Security y una PyME podria ver los datos de otra.

El `docker-compose.dev.yml` crea el rol y la base automáticamente en el primer
arranque (a partir de `POSTGRES_USER`, `POSTGRES_PASSWORD` y `POSTGRES_DB`).

Si la base se crea a mano:

```sql
CREATE ROLE portal_app LOGIN PASSWORD 'una-clave-larga'
    NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;

CREATE DATABASE portal_autoservicio OWNER portal_app;
```

---

## 2. Variables de entorno

```bash
cp .env.example .env
```

Generar `APP_KEY`:

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api php artisan key:generate
```

Generar los secretos de Authentik:

```bash
echo "AUTHENTIK_SECRET_KEY=$(openssl rand -base64 60 | tr -d '\n')"
echo "AUTHENTIK_BOOTSTRAP__TOKEN=$(openssl rand -hex 32)"
```

> El documento oficial de Authentik advierte que las contrasenas de
> PostgreSQL no pueden pesar mas de 99 caracteres, por una limitacion de
> PostgreSQL.

---

## 3. Generar el esqueleto de Laravel

Este repositorio trae los archivos **propios** del portal (modelos,
migraciones, servicios, controladores, rutas, configuración). El esqueleto de
Laravel (`artisan`, `public/index.php`, `storage/`, los demas archivos de
`config/`, `app/Providers/`) lo genera Composer.

```bash
cd api
composer create-project laravel/laravel:^13.37 . --no-interaction
```

Despues hay que **restaurar los archivos del portal**, porque
`create-project` escribe encima:

```bash
# Desde la raiz del repositorio, si el esqueleto se genero en api/:
git checkout -- api/bootstrap/app.php api/config api/routes api/app api/database api/tests
```

Y luego:

```bash
composer install
php artisan key:generate
```

Los archivos de configuración que **reemplazan** a los del esqueleto son:
`config/auth.php`, `config/session.php`, `config/logging.php`,
`config/filesystems.php` y `config/authentik.php`. Los demas se usan tal cual
los entrega Laravel.

---

## 4. Configurar el proveedor OAuth2 en Authentik

En Authentik: **Applications -> Providers -> Create**, tipo *OAuth2/OpenID
Connect*.

| Campo | Valor |
|---|---|
| Name | Portal de Autoservicio |
| Protocol | OpenID Connect |
| Redirect URI | La URL de callback, exactamente |
| Client type | Confidential |
| Client ID / Secret | Anotarlos en `.env` |
| Signing key | Generar una (para que use RS256) |

Alcances (scopes): `openid`, `profile`, `email`.

- `openid` es **obligatorio**: sin el no llega el ID token.
- `profile` entrega nombre y el claim `groups`.
- `email` entrega el correo.

"Redirect URI strict matching": activarlo. Si no, Authentik aceptaria
redirecciones a otros destinos.

Crear despues un grupo llamado, por ejemplo, `portal-administradores`, y
anotarlo en `AUTHENTIK_GRUPO_ADMIN`.

El valor de `AUTHENTIK_ISSUER` debe ser exactamente el issuer que declara el
propio Authentik. Se puede copiar del documento de descubrimiento:

```bash
curl -s https://id.ejemplo.cl/application/o/portal/.well-known/openid-configuration | jq -r .issuer
```

---

## 5. Levantar el entorno

```bash
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
```

| Servicio | URL |
|---|---|
| Interfaz | http://localhost:3000 |
| API | http://localhost:8000/api/v1 |
| Authentik | http://localhost:9000 |

El primer ingreso a Authentik pide la contrasena del usuario `akadmin`.

---

## 6. Migraciones y datos de desarrollo

```bash
docker compose -f deploy/docker-compose.dev.yml exec api php artisan migrate
docker compose -f deploy/docker-compose.dev.yml exec api php artisan migrate --seed
```

El seeder crea **dos** Pymes de ejemplo:

| slug | nombre | dominio |
|---|---|---|
| `panaderia-las-tortas` | Panaderia Las Tortas | `lastortas.cl` |
| `taller-bravo` | Taller Bravo | `tallerbravo.cl` |

Se crean dos y no una a propósito: con una sola no se puede demostrar nada del
aislamiento.

> **Los usuarios de ejemplo no pueden entrar al portal.** Sus `authentik_sub`
> son ficticios: la identidad no existe en Authentik. Sirven para probar la
> base de datos y las pruebas automatizadas, no el flujo de entrada.

---

## 7. Verificar el aislamiento

Este paso **no es opcional**:

```bash
docker compose -f deploy/docker-compose.dev.yml exec api php artisan portal:verificar-aislamiento
```

Comprueba que el rol no sea superusuario ni tenga `BYPASSRLS`, que las ocho
tablas tengan RLS activada, forzada y con política, que la función de contexto
falle cerrada, que el contexto no quede colgando despues de la transacción y
que la vista pública de tenants no exponga de mas.

Si falla, **no sigas**: el portal puede arrancar y quedar abierto entre
clientes sin dar ningun aviso visible.

---

## 8. Crear la primera PyME real

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan portal:crear-tenant panaderia-las-tortas \
    --nombre="Panaderia Las Tortas" \
    --razon-social="Panaderia Las Tortas SpA" \
    --rut="76.123.456-7" \
    --dominio=lastortas.cl
```

En producción **no** se usa el seeder: la PyME se da de alta con este comando.

---

## 9. Dar de alta a la primera persona

1. Crear la persona en Authentik.
2. Invitarla desde el portal: **Personas -> Invitar**, con su correo y rol.
3. El token aparece **una sola vez**. El portal no envia correos en este
   primer corte: la PyME lo entrega por el canal que quiera (correo, WhatsApp).
4. La persona entra por `https://portal.hefestostudio.cl/entrar?slug=panaderia-las-tortas`.

El alta real en el portal ocurre en el login: si el correo tiene el dominio
declarado por la PyME o una invitacion vigente, se crea el usuario con el rol
que el administrador eligio al invitar.

> Aviso sobre `AUTHENTIK_GRUPO_ADMIN`: el grupo solo define el primer alta.
> Si la invitacion indica el rol, ese rol manda.

---

## 10. Pruebas automatizadas

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan test --testsuite=Aislamiento
```

**Requieren PostgreSQL de verdad**, no SQLite: el aislamiento se implementa con
Row Level Security, y en SQLite estas pruebas pasarian sin comprobar nada.

Estado actual de estas pruebas: **estan escritas pero no se han ejecutado**.
Ver [10 - Pruebas](10-PRUEBAS.md).