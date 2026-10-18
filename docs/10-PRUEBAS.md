# 10 - Pruebas: que se ejecuto y que no

Este documento separa, sin adornos, lo que **se comprobo** de lo que **solo se
escribio**. Es el primero que hay que leer antes de comprometer una fecha.

---

## 1. Entorno disponible

Al momento de escribir este proyecto, en la maquina de trabajo:

| Herramienta | Estado |
|---|---|
| PHP | **No disponible** |
| Composer | **No disponible** |
| Docker | **No disponible** |
| psql (cliente) | **No disponible** |
| Node.js | Disponible |
| Python | Disponible |
| Git Bash | Disponible |
| Acceso a internet | Disponible |

Por lo tanto: **no se pudo ejecutar Laravel, ni las migraciones, ni PHPUnit, ni
un arranque de PostgreSQL con RLS, ni una construccion de Nuxt.**

---

## 2. Lo que SI se verifico

### 2.1 Versiones, contra los repositorios oficiales

Todas las versiones del stack se consultaron en Packagist, npm y Docker Hub:

| Paquete | Versión verificada |
|---|---|
| `laravel/framework` | `13.37.0` (requiere PHP `^8.3`) |
| `laravel/socialite` | `5.31.0` |
| `firebase/php-jwt` | `7.2.1` |
| `predis/predis` | `3.7.0` |
| `phpunit/phpunit` | `13.4.2` |
| `nunomaduro/collision` | `8.9.0` |
| `mockery/mockery` | `1.8.2` |
| `fakerphp/faker` | `1.24.1` |
| `nuxt` | `4.6.2` (última) / `3.21.11` (rama 3) |
| `typescript` | `^5.6` |
| `vue` | `^3.5` |

Imagenes de contenedor (tag exacto):

| Imagen | Tag |
|---|---|
| PHP fpm | `8.4.26-fpm-alpine3.23` |
| PHP cli | `8.4.26-cli-alpine3.23` |
| PostgreSQL | `18.6` |
| Redis | `8.8.3-alpine` |
| Node | `24.22.0-alpine3.24` |
| Caddy | `2.11.7-alpine` |
| Authentik | `ghcr.io/goauthentik/server:2025.10` |

### 2.2 API de Authentik

Leido de la documentacion oficial
(<https://docs.goauthentik.io/add-secure-apps/providers/oauth2/>) y del
`compose.yml` que pública el proyecto:

- Las rutas de descubrimiento: `/application/o/<slug>/.well-known/openid-configuration`,
  `/application/o/<slug>/jwks/`, `/application/o/<slug>/end-session/`,
  `/application/o/authorize/`, `/application/o/token/`, `/application/o/userinfo/`.
- Authentik admite **PKCE**, y el claim `groups` llega con el alcance
  `profile`.
- Desde **2025.10** el claim `email_verified` vale `false` por defecto.
- Desde **2025.10 Authentik elimino Redis**: son tres servicios
  (`postgresql`, `server`, `worker`), `server` y `worker` usan **la misma
  imagen** con distinto `command`, y **no existe** una imagen `worker`
  separada.

### 2.3 Código fuente de Socialite 5.31.0

Leido el código real, no la documentacion:

- **No existe un driver OIDC generico.** En `src/Two` hay controladores de
  proveedores concretos mas `AbstractProvider`. Por eso el proveedor OIDC del
  portal es código propio.
- `AbstractProvider` aporta `getAuthUrl`, `getTokenUrl`, `getTokenFields`,
  `getAccessTokenResponse`, `getCodeFields`, `buildAuthUrlFromBase`,
  `usesPKCE`, `getCodeChallenge` y `getCodeChallengeMethod` (que devuelve
  `S256`).
- `redirect()` genera el `code_verifier` y lo deja en la sesión.
- **Hallazgo importante:** en `user()`, la respuesta del endpoint de token pasa
  a `userInstance()`, donde `User::setToken()` recibe **solo el
  `access_token`**. El `id_token` se pierde. Por eso el portal sobrescribe
  `userInstance()` para recuperarlo; sin eso no se podrian verificar `iss`,
  `aud` ni `nonce`.

### 2.4 Código fuente de firebase/php-jwt 7.2.1

- `JWT::decode(string $jwt, array|Key $keyOrKeyArray, ?stdClass &$headers = null): stdClass`
- `JWK::parseKeySet(array $jwks, ?string $defaultAlg = null): array`
- `JWT::$leeway` existe y es estático.

### 2.5 Laravel

- `createApplication()` vive en `Illuminate\Foundation\Testing\TestCase`
  (leido del código de la rama `12.x`). Por eso `tests/TestCase.php` es una
  clase abstracta minima.
- `Illuminate\Foundation\Auth\User` es la clase que implementa el contrato
  `Authenticatable`. Por eso `App\Models\Usuario` **extiende esa clase** y no
  `Illuminate\Database\Eloquent\Model`: sin eso, el guard de sesión no acepta
  el modelo.
- La ruta de salud se declara con `health:` en `withRouting()`.

### 2.6 Verificaciones estaticas que si se ejecutaron

Sobre los archivos del repositorio, con scripts propios
(`herramientas/balanceo.py` y otros):

| Verificación | Resultado |
|---|---|
| Balance de delimitadores en PHP, TS, Vue, JSON y YAML (101 archivos) | Sin desbalances |
| Barrido de caracteres CJK y cirilicos (125 archivos, **tres pasadas**) | La primera encontro **6** fallos, todos corregidos. La segunda y la tercera, limpias |
| Barrido de secretos (20 archivos de configuración) | Sin hallazgos |
| JSON valido (`package.json`, `composer.json`, `tsconfig.json`) | 3 de 3 validos |
| YAML valido (`docker-compose.*.yml`) | 2 de 2 validos |
| Referencias ruta <-> controlador | 18 referencias, todas resueltas; ningun controlador huerfano |
| Puertos publicados | Desarrollo: api, web, postgres, redis, authentik. Producción: solo `caddy` |

Los seis fallos de la primera pasada del barrido eran fragmentos de otros
idiomas que se colaron al escribir (cirilico en un documento, ideogramas en
dos archivos de código). El barrido esta en
`herramientas/verificar_caracteres.py` y se puede volver a correr en cualquier
momento.

> Estas verificaciones detectan desbalances de sintaxis y caracteres
>Rare, **no** compilan el código. Un archivo balanceado puede tener
> todavia un error de tipo o de nombre de clase.

---

## 3. Lo que NO se ejecuto

Esto es lo que falta. **Ninguna de estas cosas se hizo.**

### 3.1 PHP

- **No se compilo ni un solo archivo PHP** con `php -l`. El verificador de
  balance es un proxy, no un compilador.
- **No se instalaron las dependencias** (`composer install`).
- **No se arranco Laravel.**
- **No se corrieron las migraciones** contra un PostgreSQL real. El SQL de las
  11 migraciones **no ha sido validado por PostgreSQL**.

### 3.2 Pruebas automaticas

Las pruebas estan escritas y **no se ejecutaron ninguna**:

| Suite | Ubicacion | Estado |
|---|---|---|
| Unitarias (maquina de estados) | `api/tests/Unit/` | Escritas, no ejecutadas |
| Funcionales (solicitudes) | `api/tests/Feature/Solicitudes/` | Escritas, no ejecutadas |
| **Aislamiento** | `api/tests/Feature/Aislamiento/` | Escritas, **no ejecutadas** |

Las de aislamiento son las mas importantes y las que mas conviene correr
primero. Requieren **PostgreSQL de verdad**, no SQLite: el aislamiento se
implementa con Row Level Security, y en SQLite pasarian sin comprobar nada.

```bash
php artisan test --testsuite=Aislamiento
```

**Advertencia sobre las pruebas de API y la RLS:** `RefreshDatabase` envuelve
cada prueba en una transacción, y el middleware `ResolverTenant` abre una
transacción anidada (un *savepoint*). Como el contexto se fija con
`set_config(..., true)` (alcance de transacción), su comportamiento dentro de
un *savepoint* no se ha podido comprobar. Por eso `tests/TestCase.php` fija el
contexto con **alcance de sesión**, para que las aserciones sigan viendo el
tenant. Esto **no se valido en ejecución**: es una decisión razonada que puede
necesitar ajuste cuando las pruebas corran de verdad.

### 3.3 Frontend

- **No se ejecuto `npm install`.**
- **No se corrio `nuxt build`.**
- **No se corrio `nuxt typecheck`.** Los tipos de `app/tipos/api.ts` son una
  copia manual de lo que devuelven los Resources: pueden no coincidir.
- **No se probo el BFF** (`web/server/api/[...ruta].ts`) contra una API real.

### 3.4 Seguridad

- **No hay auditoria de seguridad.**
- **No hay pruebas de penetracion.**
- El BFF reenvia `set-cookie` usando `getSetCookie()` cuando existe y un
  fallback cuando no. **No se verifico** contra la versión de `h3` que
  instala Nuxt 4.

### 3.5 Despliegue

- **No se construyo ninguna imagen.**
- **No se levanto `docker compose`.**
- **No se genero ningun certificado TLS.**

---

## 4. Riesgos abiertos

Ordenados por lo que duelen si salen mal:

1. **Las pruebas de aislamiento no se ejecutaron.** Si el SQL de las
   migraciones tiene un error, el aislamiento se cae entero y la aplicación
   no da ningun aviso visible.

2. **El SQL nunca fue validado por PostgreSQL.** Once migraciones, una función
   `plpgsql`, ocho políticas y una vista, escritas sin poder ejecutarlas.

3. **El frontend no compilo.** Los tipos son manuales y el BFF usa funciones de
   `h3` cuya versión no se confirmo.

4. **Las imagenes no estan fijadas por digest.** Solo por tag exacto. La
   imagen `composer:2` no esta fijada de ninguna manera.

5. **Sin limites de tasa.** El endpoint de autenticación es el primero que se
   ataca. Ver [08 - Seguridad](08-SEGURIDAD.md).

6. **La tabla `tenants` sin RLS.** Es a propósito y esta compensado, pero
   quien tenga SQL lee los datos de contacto de todas las Pymes.

7. **No hay `Content-Security-Policy`.**

---

## 5. Orden recomendado para retomar el trabajo

1. `composer install` y `php -l` sobre todos los archivos de `api/`.
2. Levantar PostgreSQL y correr `php artisan migrate`.
3. `php artisan portal:verificar-aislamiento` (debe pasar).
4. `php artisan test --testsuite=Aislamiento` (debe pasar).
5. El resto de las pruebas.
6. `npm install && npm run typecheck && npm run build` en `web/`.
7. Levantar el `compose` de desarrollo y recorrer el flujo completo con una
   PyME real en Authentik.
8. Recien ahi, decidir sobre producción.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`portal_autoservicio_admin`) y el rol de
la aplicación (`portal_app`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[08-SEGURIDAD.md](08-SEGURIDAD.md). **No se ha ejecutado ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. El
paso 3 del orden recomendado de arriba ya recogía el comando, y sigue siendo el
primer paso real: nada de esto se puede comprobar hasta que haya una base.

Queda pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `portal_app` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan portal:verificar-aislamiento` y confirmar que sale con
   código 0.
4. Probar el escenario que motiva todo el cambio: una PyME intentando leer
   documentos de otra.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.