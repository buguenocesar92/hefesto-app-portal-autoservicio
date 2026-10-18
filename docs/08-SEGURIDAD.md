# 08 - Seguridad

Este documento lista lo que se hizo, por que, y **que queda sin resolver**.
No es un informe de auditoria: no se hizo ninguna.

---

## 1. Amenaza principal

**Fuga de datos entre Pymes.** Todo lo demas es menos grave. El tratamiento
completo esta en [03 - Multi-tenancy](03-MULTITENANCY.md); aquí solo el
resumen: el aislamiento lo impone la base de datos (Row Level Security), no
solo el código, y falla cerrado.

---

## 2. Sesión y cookies

| Medida | Detalle |
|---|---|
| Cookie **HttpOnly** | El JavaScript de la pagina no puede leerla. No hay token en `localStorage` |
| Cookie **Secure** | Solo viaja por HTTPS. Obligatorio en producción (`SESSION_SECURE_COOKIE=true`) |
| Cookie **SameSite=Lax** | `strict` impediria el ingreso: la cookie no viaja en la redireccion desde Authentik |
| `session.use_strict_mode = 1` | Un identificador de sesión no generado por la aplicación se rechaza |
| Sesión en base de datos | Multiples replicas sin perder la sesión |
| Regeneracion de identificador | Al iniciar sesión y en cada `login` se regenera (ataque de fijacion de sesión) |

### La sesión vive en una sola origen

El navegador nunca habla directo con la API: va al BFF de Nuxt, que reenvia las
cookies. Por eso una cookie `SameSite=Lax` basta y no hace falta CORS con
credenciales. Ver [01](01-ARQUITECTURA.md), seccion 3.

---

## 3. CSRF

El grupo `web` de Laravel aplica `VerifyCsrfToken`. El token viaja como la
cabecera **`X-XSRF-TOKEN`**, que el BFF copia desde la cookie `XSRF-TOKEN`.

Consecuencias de esa eleccion:

- El token no es accesible desde JavaScript de la pagina (esta en una cookie
  sin `HttpOnly`, pero solo lo lee el servidor del BFF, nunca el código del
  cliente).
- No hace falta exponer el token en el HTML ni en una variable global.
- Toda escritura (`POST`, `PATCH`) pasa por el BFF, así que todas pasan por la
  comprobacion.

---

## 4. Autorización

Dos capas, y estan separadas a propósito:

| Capa | Donde | Que decide |
|---|---|---|
| Row Level Security | PostgreSQL | De que PyME son los datos |
| Policies | Laravel | Que puede hacer la persona dentro de su PyME |

Reglas que importan:

- El cliente **nunca** cambia el estado de una solicitud.
- El cliente **nunca** ve solicitudes de otra persona de su misma PyME.
- El administrador de una PyME **no** puede tocar nada de otra PyME.
- Un recurso de otra PyME responde **404**, no 403.
- No se puede quitar el rol de administrador a la última administradora o
  administrador de la PyME.

---

## 5. Validación de entrada

- **Todas** las entradas que llegan a la base pasan por un `FormRequest` con
  reglas explicitas, o por validación en el controlador.
- Los datos dinamicos de una solicitud se validan contra la definicion de
  campos de la categoria, no se guardan tal cual.
- Los mensajes de error estan en espanol y son para la persona, no para el
  desarrollador.
- Las consultas usan **binding** de Eloquent o del query builder. No hay SQL
  armado con cadenas en ningun punto.
- Las búsquedas de texto usan `ilike` con parámetros, no concatenacion.

### Busqueda de usuarios y solicitudes

Se usa `ilike` con `%` alrededor. Con muchos tenants el `%` al inicio impide
aprovechar el indice, así que en el futuro conviene `pg_trgm`. **No es un
problema de seguridad**, es de rendimiento.

---

## 6. Rutas y archivos

**No hay carga de archivos desde la interfaz** en este primer corte. El riesgo
de traversal se mitiga aun así en la descarga:

```php
$rutaReal = realpath($disco->path($rutaRelativa));
$baseReal = realpath($disco->path(''));
if (! str_starts_with($rutaReal, $baseReal . DIRECTORY_SEPARATOR)) {
    abort(404);
}
```

Es decir: aunque alguien escribiera `../../../app/.env` en `ruta_archivo`, la
descarga devuelve 404.

Los documentos **no** se sirven como contenido estático: se autorizan y se
entregan desde la API, con la respuesta marcada `Cache-Control: private,
no-store`.

---

## 7. Redireccion abierta

`redirigir_a` (a donde volver despues de entrar) solo acepta rutas internas. Se
rechazan las URL completas, las que empiezan con `//` (protocolo relativo) y
las que empiezan con `/\` (que algunos navegadores leen como protocolo
relativo).

---

## 8. Datos sensibles

| Dato | Decisión |
|---|---|
| `authentik_sub` | Marcado como oculto en el modelo y **no** aparece en ningun Resource |
| Token de invitacion | Se guarda **solo el hash SHA-256**. El token en claro se muestra una vez y no se persiste |
| Contrasenas | El portal **no tiene** contrasenas. Vive todo en Authentik |
| `APP_KEY` | Solo en variables de entorno. `.env` esta en `.gitignore` |
| Secretos de Authentik | Solo en el `.env` de despliegue |

---

## 9. Trazabilidad

**Despues del login** (con tenant): tabla `bitacora_accesos`, en modo
append-only. Registra accion, ruta, metodo, direccion IP, agente de usuario y
si la operación fue exitosa. No hay interfaz de lectura: se consulta por
consola.

**Antes del login** (sin tenant): canal de log `portal_seguridad`, en JSON. Se
registran los rechazos de entrada, los cruces de dominio rechazados, los
intentos de alta sin invitacion y el acceso de usuarios desactivados.

La razon de la separacion es técnica: `bitacora_accesos.tenant_id` es NOT NULL
(no hay hechos sin PyME) y la política RLS lo exige. Un intento con estado
invalido no tiene tenant, así que no podria escribirse ahi.

Un fallo al escribir la bitacora **nunca** tumba la peticion del cliente: se
avisa por log y la respuesta sigue su curso. Perder una linea de bitacora es
menos malo que perder una solicitud.

---

## 10. Limitaciones de la sesión y del login

**`email_verified` no decide nada.** Desde Authentik 2025.10 ese claim vale
`false` salvo que se configure el flujo que lo verifica. El alta se decide por
dominio declarado o por invitacion vigente.

**La identidad la demuestra Authentik; el permiso lo decide la PyME.** Un grupo
de Authentik no da permiso permanente: solo define el primer alta.

**El nonce** se lee una sola vez y el intento de entrada tiene una ventana de
10 minutos.

**El algoritmo de firma se espera**, no se acepta el que venga en la cabecera:
esto cierra el ataque de confusion de algoritmo (RS256 -> HS256 firmado con la
clave pública).

---

## 11. Encabezados HTTP

Caddy los agrega globalmente (ver `deploy/caddy/Caddyfile`):

| Encabezado | Valor | Por que |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Obligar a HTTPS |
| `X-Content-Type-Options` | `nosniff` | Que el navegador no adivine el tipo de contenido |
| `X-Frame-Options` | `DENY` | Que nadie meta el portal en un iframe |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | No filtrar URLs completas |
| `Server` | (eliminado) | No anunciar la tecnologia |

---

## 12. Lo que NO se hizo

Esto es importante: **no hubo auditoria de seguridad, no hubo pruebas de
penetracion y no hubo revision externa**. Lo que hay es el analisis de este
documento y del código.

Pendientes conocidos:

1. **Las pruebas de aislamiento estan escritas pero no se ejecutaron** (no
   habia PostgreSQL disponible). Ver [10](10-PRUEBAS.md).
2. **No hay limites de tasa (rate limiting)** en los endpoints. El de
   autenticación deberia tenerlos: es lo primero que se ataca.
3. **No hay CSP** (Content-Security-Policy). Habria que anadirla junto con el
   nonce de los scripts.
4. **No hay rotacion de `APP_KEY`**. Si se filtra, hay que rotarlo y eso
   invalida todas las sesiones.
5. **La bitacora no tiene política de retencion** automática. Hay que decidirla
   (los requisitos del SII aplican a documentos, no a bitacoras).
6. **El código del canal de seguridad no filtra datos personales.** Hojea el
   correo en algunos mensajes (`alta_rechazada_sin_invitacion`). Es
   intencional, pero conviene revisarlo según la política de la PyME.
7. **No hay doble factor de autenticación** gestionado por el portal. Authentik
   puede ofrecerlo, pero hay que configurarlo ahi.
8. **La tabla `tenants` no tiene RLS** (a propósito). Quien tenga SQL puede leer
   la razon social, el RUT y los contactos de todas las Pymes.

---

## 13. Revision de secretos antes de publicar

Nunca subir un `.env`. Antes de un commit:

```bash
git status --short | grep -E '\.env$|composer\.lock$'
```

`composer.lock` **si** se versiona (reproducibilidad), pero `.env` no. Los
`.env.example` no llevan ningun valor real: solo nombres de variable.

---

## 14. Los dos roles de PostgreSQL

La base del portal corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre PyMEs funcione en vez de
quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `portal_autoservicio_admin` | `POSTGRES_USER` | Crear la base `portal_autoservicio` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `portal_app` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de aplicación se llama `portal_app`, no `portal_autoservicio_app`. El
nombre corto comparte prefijo con la aplicación hermana `portal-onboarding`, que
también usa un rol llamado `portal`. Son bases distintas y credenciales
distintas, pero conviene no confundirlas al leer un `.env`.

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política se iba a aplicar: una PyME habría visto
los documentos y los datos de las demás, sin error y sin aviso. El aislamiento
entre PyMEs era decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE portal_autoservicio OWNER TO portal_app;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan portal:verificar-aislamiento
```

El prefijo del comando es `portal:`, no `portal-autoservicio:`. El comando
consulta `pg_roles` sobre el rol de la conexión y falla con código 1 si ese rol
es superusuario o tiene `BYPASSRLS`, y después revisa que la RLS, las políticas
y el contexto de tenant protejan los datos de cada PyME.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS.md](10-PRUEBAS.md).