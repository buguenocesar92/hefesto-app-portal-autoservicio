# 03 - Multi-tenancy: como se evita que una PyME vea los datos de otra

Este es el documento mas importante del proyecto. El riesgo número uno del
portal no es que falle una pantalla: es que el cliente de la Panaderia Las
Tortas vea las solicitudes del Taller Bravo. Todo lo demas (la interfaz, la
rapidez, el diseno) es menos importante que esto.

---

## 1. El problema, en una frase

Una sola instalación de PostgreSQL guarda los datos de todas las Pymes, y cada
peticion HTTP trae un `tenant_id` que dice a quien pertenece lo que se pide.
Si en algun punto una consulta se ejecuta sin ese `tenant_id`, o con el de
otro, el sistema **no da ningun aviso**: devuelve filas que no deberia y la
interfaz se ve perfecta.

Por eso el aislamiento **no puede depender solo del código de la
aplicación**. Se implementa en dos capas:

| Capa | Que hace | Que detecta |
|---|---|---|
| Aplicación (Policies y middleware) | Decide y filtra | Errores de lógica, consultas mal escritas |
| Base de datos (Row Level Security) | Impide a PostgreSQL devolver filas ajenas | Todo lo que se le escape a la capa de arriba |

La base es la última linea. Si la aplicación tiene un error, la base sigue
sin entregar datos de otra PyME.

---

## 2. Lo que se eligio: columna `tenant_id` + RLS

Todas las tablas de negocio llevan `tenant_id`, y PostgreSQL aplica una
política de **Row Level Security** que exige que `tenant_id` sea igual al de la
peticion.

### Por que no un esquema por PyME (la alternativa obvia)

La alternativa clasica en PostgreSQL es darle a cada PyME su propio esquema
(`cliente_1.solicitudes`, `cliente_2.solicitudes`). Se evaluo y se descarto.
Estos son los motivos concretos:

**1. Las migraciones se multiplican.** Con esquema por cliente, cada migración
tiene que aplicarse en TODOS los esquemas. Con 30 Pymes son 30 ejecuciones.
Con 300, 300. Un cambio de esquema se vuelve un proceso largo y fragil, y cada
ejecución es una oportunidad de que unas queden desalineadas: un cliente
con la columna nueva y otro sin ella, justo cuando una consulta nueva recorre
las dos.

**2. El pool de conexiones se vuelve el cuello de botella.** El pool de
conexiones se dimensiona por el número de combinaciones (esquema, rol) que se
usan de verdad. Con muchos clientes, un pool razonable deja de alcanzar en
horas punta. Con columna única, todas las peticiones comparten el mismo
pool.

**3. Laravel obliga a duplicar la lógica de esquema.** La conexión pasa a
depender del usuario que esta conectado, y cada consulta, cada migración,
cada prueba y cada cola necesita acordarse del esquema correcto. Es la clase
de acoplamiento que produce fugas: un `DB::table(...)` olvidado, una relacion
de Eloquent mal definida.

**4. Las herramientas de administración se complican.** Respaldar una PyME es
`pg_dump -n cliente_1`, lo cual suena bien, pero en la práctica obliga a tener
scripts bespoke por cliente y el operador termina respaldando la base entera
igual.

**5. El aislamiento queda en manos de la aplicación.** El `search_path` es un
parámetro de la sesión. Con esquema por cliente, si el `search_path` queda mal
puesto (una conexión reutilizada del pool, un error en el middleware), la
consulta va al esquema equivocado y **no falla**: devuelve datos de la PyME
equivocada sin error visible. Es exactamente el fallo que RLS evita, porque en
RLS el tenant viaja en la propia fila y la política compara fila por fila.

### Por que entonces columna única + RLS

- Una sola migración, un solo esquema, un solo pool.
- La política se aplica en la base, no en PHP: no se puede "olvidar".
- Un `where tenant_id = ?` olvidado no fuga datos: filtra de mas, que es un
  error visible, no invisible.
- Respaldar es `pg_dump` de una base, sin scripts por cliente.

El costo es que **todas** las consultas deben tener el contexto de tenant. Ese
costo esta resuelto y forzado por el diseno (seccion 4).

---

## 3. El mecanismo, en detalle

Definido en `api/database/migrations/2026_01_05_000009_activar_row_level_security.php`.

### 3.1 La función de contexto

```sql
CREATE FUNCTION public.app_tenant_id() RETURNS uuid ...
    valor := current_setting('app.tenant_id', true);
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION ...
    RETURN valor::uuid;
```

La función **lanza excepcion** si no hay tenant. Podria haber devuelto NULL y
filtrado todo silenciosamente, pero se eligio que falle de forma ruidosa:

- Si devuelve NULL, la consulta devuelve cero filas sin aviso. Alguien lo
  "arregla" devolviendo NULL cuando hace falta (un reporte, un comando de
  consola) y la puerta queda abierta sin que nada lo delate.
- Si lanza excepcion, el error aparece en el log **en el momento**, y se sabe
  exactamente que operación se escapo del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### 3.2 La política

Para cada tabla con datos de negocio:

```sql
ALTER TABLE public.solicitudes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.solicitudes FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_solicitudes ON public.solicitudes
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles que importan:

- `USING` filtra lo que se **lee**.
- `WITH CHECK` impide **escribir** una fila con un `tenant_id` distinto al del
  contexto. Sin esto, un `INSERT` con el tenant equivocado pasaria la lectura y
  dejaria una fila invisible que nadie podria ver ni borrar.
- `FORCE ROW LEVEL SECURITY` hace que la política aplique **también al dueno de
  la tabla**. Sin FORCE, el dueno la saltaria. En desarrollo el rol de la
  aplicación suele ser el mismo que creo las tablas, o sea el dueno: sin
  FORCE, el aislamiento seria decorativo justo en el entorno donde se prueba.

### 3.3 Como viaja el tenant

El contexto se fija con:

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
```

El `true` final es lo mas importante del archivo: significa **alcance de
transacción**. Al confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (alcance de sesión), el valor sobreviviria a la peticion. Y
el worker de colas **reutiliza la misma conexión** para trabajos de Pymes
distintas: un `SET` a sesión significa que un trabajo de la Panaderia podria
leer los datos del Taller. Ese es exactamente el fallo que este diseno evita,
y por eso `ContextoTenant::establecer()` **lanza excepcion si no esta dentro
de una transacción**.

### 3.4 Quien fija el contexto

Solo una clase: `App\Services\Tenancy\ContextoTenant`, y solo a traves del
middleware `App\Http\Middleware\ResolverTenant`, que envuelve la peticion
autenticada en una transacción con el tenant ya fijado.

---

## 4. El punto ciego: la tabla `tenants`

`tenants` **no lleva RLS**, a propósito. Se consulta durante el login, cuando
todavia no hay sesión y por lo tanto no hay `app.tenant_id` que poner.

Es una excepcion consciente, con dos compensaciones:

**Compensacion 1: hoy no está activa.** El código lee la **tabla** `tenants`, no
una vista. `App\Services\Auth\ResolvedorTenant` resuelve la PyME por `slug` con
`Tenant::where('slug', ...)->where('activo', true)->first()` y por
`dominio_correo` con `Tenant::where('dominio_correo', ...)->where('activo', true)
->first()`. Ninguna de las dos usa `select()`, así que traen la fila completa. Antes
del login no hay `app.tenant_id`, y por eso `tenants` es la excepción controlada del
patrón.

La tabla `tenants` guarda `razon_social`, `rut`, `correo_contacto` y
`telefono_contacto`. **El código no los devuelve al navegador antes de
autenticarse**: la resolución ocurre en el servidor y de la fila solo se usa el
identificador para continuar el ingreso. Lo que se expone hacia el exterior son
columnas de identificación, nunca datos de contacto, y en este portal tampoco hay
datos de pacientes: los documentos y las solicitudes viven en tablas con RLS.

La vista `vw_tenants_publicos` **existe** (migración
`2026_01_05_000011_crear_vista_tenants_publicos.php`, que expone `slug`, `nombre` y
`activo` de las Pymes activas) y **queda sin uso**: el código de ejecución nunca la
consulta. La vista no se eliminó: se conserva como defensa en profundidad y como
respaldo para cuando se decida activar la protección.

**Pendiente, no comprobado:** activar la vista exige revisar qué espera la interfaz
del ingreso y si la fila completa aporta algo que la vista no traiga. No se ha
podido comprobar contra una base de datos porque no había una disponible, así que
la decisión sigue abierta. Una versión anterior de este documento afirmaba que la
aplicación leía la vista; era falso y se corrige aquí.

**Compensacion 2: el rol de la aplicación no es superusuario.** Quien tenga
acceso SQL directo a la base puede leer `tenants` entera. Por eso el despliegue
exige revisar los permisos y `portal:verificar-aislamiento` comprueba que el
rol no sea superusuario ni tenga `BYPASSRLS`.

**Riesgo residual, dicho con todas las letras:** un `slug` se puede enumerar
antes de autenticarse, porque el slug es público (aparece en la URL de
entrada y en los enlaces que la PyME reparte). Un atacante puede descubrir que
la Panaderia Las Tortas usa el portal. No puede ver mas que su nombre. Si
incluso eso se considera unacceptable, la alternativa es dejar el campo en
blanco hasta el login y resolver siempre por dominio de correo; queda
anotado como decisión pendiente del cliente, no como algo resuelto.

---

## 5. Doble filtro dentro del tenant

La RLS ya garantiza que nada sale del tenant. Dentro del tenant hay un segundo
filtro, en las Policies:

| Recurso | Cliente | Administrador de la PyME |
|---|---|---|
| Solicitudes | solo las que creo | todas las de su PyME |
| Documentos | los de su PyME, mas los dirigidos a el | todos los de su PyME |
| Personas | ninguna (es de la PyME) | todas las de su PyME |
| Cambiar el estado de una solicitud | nunca | si |
| Categorias activas | si | si |

Que el cliente no pueda cambiar el estado de su propia solicitud es una regla
de negocio, pero también de seguridad: si pudiera, podria cerrar su orden sin
que nadie la mirara.

Cuando una Policy rechaza, la respuesta es **404, no 403**: un 403 confirmaria
que el recurso existe. El filtro por tenant, sin embargo, impide que el
recurso exista a ojos de la otra PyME, así que 404 es ademas la respuesta
correcta en el caso habitual.

---

## 6. El riesgo número uno, y que se hizo para reducirlo

**Riesgo: fuga de datos entre Pymes.**

Mitigaciones, en orden de fuerza:

1. **La base de datos lo impide**, no la aplicación. Un error en PHP no basta
   para filtrar datos.
2. **La política tiene `WITH CHECK`.** No se pueden crear filas en nombre de
   otra PyME.
3. **El contexto tiene alcance de transacción.** El worker de colas no puede
   arrastrar el tenant de otra PyME.
4. **`FORCE ROW LEVEL SECURITY`.** El dueno de la tabla tampoco salta la
   política.
5. **La función de contexto falla cerrado.** Una consulta sin tenant se
   rompe de forma ruidosa en vez de devolver nada en silencio.
6. **Pruebas dedicated de aislamiento**, que corren contra PostgreSQL de
   verdad (no contra SQLite) y verifican el cruce con identificadores reales
   de otra PyME. Ver `api/tests/Feature/Aislamiento/`.
7. **`portal:verificar-aislamiento`**, que se corre en el despliegue y falla si
   el rol de la base tiene `BYPASSRLS`, si alguna tabla quedo sin política, o
   si la función de contexto dejo de fallar cerrada.

**Lo que NO se hizo y sigue siendo un riesgo:**

- No hay revision de seguridad externa.
- No hay pruebas de penetracion.
- Las pruebas de aislamiento **estan escritas pero no se ejecutaron** (no habia
  PostgreSQL disponible al escribirlas). Ver [10](10-PRUEBAS.md).
- La política protege el dato, pero no el archivo: los documentos se sirven
  desde la API despues de autorizar, y la comprobacion de ruta se hace en
  `DocumentoController::descargar`. Si se escribiera una ruta maliciosa en la
  base, la comprobacion impide salir del volumen.

---

## 7. Resumen en una tabla

| | Esquema por PyME (descartado) | Columna + RLS (elegido) |
|---|---|---|
| Migraciones | Una por esquema x N clientes | Una sola |
| Pool de conexiones | Se dimensiona por cliente | Uno solo |
| Aislamiento | En la aplicación (`search_path`) | En la base de datos |
| Error de contexto | Falla en silencio | Falla ruidosamente |
| Riesgo de fuga | Alto (depende de cada consulta) | Bajo (la base lo impide) |
| Complejidad en Laravel | Alta | Baja |