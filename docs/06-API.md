# 06 - API

Todas las rutas cuelgan de `/api/v1` y viven en el grupo `web` de Laravel:
comparten sesión, cookie y CSRF con el resto de la aplicación.

Las consume el BFF de Nuxt (`web/server/api/[...ruta].ts`), que reenvia las
cookies en ambos sentidos. El navegador nunca habla directo con la API.

---

## 1. Convenciones

**Formato.** JSON en entrada y salida. La única excepcion es
`GET /documentos/{id}/descargar`, que devuelve el archivo.

**Errores de validación.** `422` con la forma estandar de Laravel:

```json
{
  "message": "The titulo field is required.",
  "errors": { "titulo": ["Ponle un titulo breve a tu solicitud."] }
}
```

**Sin sesión.** `401`. El middleware `auth` responde JSON siempre que la
peticion envie `Accept: application/json`, que es lo que hace el BFF. Sin esa
cabecera, Laravel devolveria una redireccion, que en una API no sirve.

**Recurso de otro tenant.** `404`, nunca `403`. Un `403` confirmaria que el
recurso existe en otra PyME, lo que ya es filtrar información. El Manejador de
excepciones de `bootstrap/app.php` convierte los `AuthorizationException` en
`404` dentro de `/api/v1/*`.

**Paginacion.** `?per_page=N` (entre 1 y 50, por defecto 15) y la forma
estandar de Laravel: `data`, `meta`, `links`.

---

## 2. Autenticación

| Metodo | Ruta | Autenticado | Que hace |
|---|---|---|---|
| GET | `/auth/login` | no | Devuelve la URL de Authentik. Acepta `?slug=` y `?redirigir_a=` |
| GET | `/auth/callback` | no | Canje del código, verificación del token y apertura de sesión |
| POST | `/auth/logout` | si | Cierra la sesión del portal |
| GET | `/auth/usuario` | si | Quien esta conectado, con su PyME |

`redirigir_a` solo acepta rutas internas: una URL completa, `//host` o `/\host`
se rechazan y vuelven a `/`. Es una redireccion abierta si no se filtra.

El `callback` responde JSON, pero el BFF detecta esa ruta y **redirige al
navegador** en vez de mostrarle un JSON. Si falla, redirige a
`/entrar?error=<mensaje>`.

---

## 3. Solicitudes (modulo central)

| Metodo | Ruta | Quien | Que hace |
|---|---|---|---|
| GET | `/solicitudes` | autenticado | Listado con filtros |
| POST | `/solicitudes` | autenticado | Abre una solicitud |
| GET | `/solicitudes/{id}` | dueno o admin | Detalle |
| GET | `/solicitudes/{id}/eventos` | dueno o admin | Bitacora |
| POST | `/solicitudes/{id}/eventos` | dueno o admin | Comentario (no cambia el estado) |
| PATCH | `/solicitudes/{id}/estado` | **solo admin** | Mueve el estado |

### Filtros del listado

| Parámetro | Ejemplo | Que hace |
|---|---|---|
| `estado` | `recibida` | Filtra por estado. Un valor desconocido se ignora |
| `q` | `fridge` | Busca en titulo y folio |
| `per_page` | `30` | Tamano de pagina (maximo 50) |

El cliente ve **solo sus** solicitudes; el administrador ve todas las de su
PyME.

### Crear una solicitud

```json
{
  "categoria_id": "uuid-o-null",
  "titulo": "El refrigerador suena fuerte",
  "descripcion": "Desde hace una semana hace un ruido fuerte.",
  "prioridad": "alta",
  "datos": { "numero_serie": "RF-88213", "franja_horaria": "manana" }
}
```

El folio lo genera la API (`SOL-00001`, `SOL-00002`, ...) y es **único por
PyME**: la Panaderia y el Taller empiezan numerando desde 1.

`datos` se valida contra los campos que declara la categoria. Un
`categoria_id` de otra PyME se comporta como inexistente (error 422), porque la
consulta de existencia corre dentro del contexto del tenant y la RLS la
aplica.

La primera entrada de la bitacora (`SolicitudEvento`) se escribe con la
solicitud, en la misma transacción.

### Estados

```
recibida ──> en_revision ──> agendada ──> en_ejecucion ──> resuelta ──> cerrada
    │            │              │             │                 │
    └────────────┴──────────────┴─────────────┴──> espera_cliente
    └──────────────────────────────────────────> anulada
```

- **El cliente NUNCA cambia el estado.** Solo abre solicitudes y comenta.
- `cerrada` y `anulada` son terminales: no se sale de ahi.
- Al pasar a un estado terminal se guarda `cerrada_en`.

Un comentario **no** cambia el estado: se registra con el estado actual y
`estado_anterior` en `NULL`. Eso es lo que permite a la interfaz distinguir un
cambio de un comentario (`es_cambio_de_estado`).

Un cambio de estado invalido responde `422` con el mensaje que redacta
`MaquinaDeEstadosSolicitud`.

---

## 4. Documentos

| Metodo | Ruta | Que hace |
|---|---|---|
| GET | `/documentos` | Listado con filtros `tipo`, `desde`, `hasta` |
| GET | `/documentos/{id}/descargar` | Devuelve el archivo |

Tipos: `factura`, `boleta`, `orden_trabajo`, `guia`, `contrato`, `otro`.

Un documento con `usuario_id` es de esa persona concreta. Uno sin `usuario_id`
es de toda la PyME.

La descarga **no** entrega una ruta de archivo: reautoriza, comprueba que la
ruta real siga dentro del volumen y sirve el archivo. Si el archivo no existe,
responde `404` con un mensaje claro.

> El portal no emite documentos: pública los que la PyME ya emitio. Ver
> [09 - Alcance](09-ALCANCE.md).

---

## 5. Historial

`GET /historial` devuelve tres cosas:

```json
{
  "resumen": {
    "solicitudes_totales": 12,
    "solicitudes_activas": 3,
    "documentos_totales": 8
  },
  "solicitudes": { "data": [...], "meta": {...} },
  "documentos":  { "data": [...], "meta": {...} }
}
```

Se divide en dos listas y no en una sola mezcla porque paginan distinto: una
persona puede tener diez solicitudes y un documento.

---

## 6. Categorias

| Metodo | Ruta | Quien | Que hace |
|---|---|---|---|
| GET | `/categorias` | autenticado | **Solo activas.** Es lo que usa el formulario de solicitud |
| GET | `/admin/categorias` | solo admin | Todas, incluidas inactivas |
| POST | `/admin/categorias` | solo admin | Crea una categoria |
| PATCH | `/admin/categorias/{id}` | solo admin | Actualiza una categoria |

Las dos habilidades (`verListado` y `administrar`) estan separadas a propósito
en `CategoriaServicioPolicy`: mezclarlas significaria que un cliente que
adivinara la ruta `/admin/categorias` veria las categorias dadas de baja.

Las dos respuestas usan `?campos=1` para pedir la definicion de campos, que en
los listados viene oculta.

Formato de un campo dinamico:

```json
{
  "clave": "numero_serie",
  "etiqueta": "Numero de serie",
  "tipo": "texto",
  "requerido": true,
  "ayuda": "Esta en la etiqueta del equipo",
  "opciones": []
}
```

Tipos admitidos: `texto`, `numero`, `fecha`, `seleccion`, `booleano`. Un campo
`seleccion` **debe** tener al menos una opcion: si no, nadie podria llenarlo.

---

## 7. Administración de la PyME

| Metodo | Ruta | Que hace |
|---|---|---|
| GET | `/admin/usuarios` | Lista personas. Filtros: `activo`, `rol`, `q` |
| POST | `/admin/usuarios` | Crea una **invitacion** (no crea la persona) |
| PATCH | `/admin/usuarios/{id}` | Cambia `rol` y `telefono` |
| POST | `/admin/usuarios/{id}/desactivar` | Desactiva (nunca borra) |

`POST /admin/usuarios` responde `201` con la invitacion y el token **en
claro**, una sola vez:

```json
{
  "invitacion": { "id": "...", "correo": "persona@empresa.cl", "rol": "cliente",
                  "expira_en": "2026-02-04T12:00:00+00:00" },
  "token": "a3f9....",
  "aviso": "El token se muestra una sola vez. Entregalo a la persona; despues no se puede recuperar."
}
```

El `correo` **no** se puede cambiar por esta API: es el vinculo con Authentik y
cambiarlo a mano dejaria una identidad que ya no corresponde.

---

## 8. Salud

`GET /api/v1/salud` es la ruta de salud de Laravel. No exige sesión, no toca
la base y devuelve el documento de salud estandar.

Es la que usa el `healthcheck` de los contenedores en producción.

---

## 9. Convenciones de nombres

- Rutas y respuestas: espanol, en minúsculas.
- Campos de la base de datos: espanol con guion baja (`categoria_id`,
  `ultimo_acceso`).
- Nombres de clase: espanol (`SolicitudController`, `ContextoTenant`,
  `MaquinaDeEstadosSolicitud`).
- Nombres de metodo: espanol, verbos en infinitivo o en gerundio
  (`resolver`, `cambiarEstado`, `validarCamposDinamicos`).