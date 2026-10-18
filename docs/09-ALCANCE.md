# 09 - Alcance

## 1. Lo que el portal SI hace

Estos son los cinco modulos del primer corte:

| Modulo | Que resuelve |
|---|---|
| **Autenticación** | La persona entra con su cuenta de Authentik y el portal sabe a que PyME pertenece |
| **Solicitudes de servicio** | Abre, sigue y cierra solicitudes. Es el modulo central |
| **Documentos** | Descarga de facturas, boletas, ordenes de trabajo, guias y contratos que la PyME ya emitio |
| **Historial** | Todo lo que pidio y todo lo que le entregaron, con fecha y estado |
| **Administración del tenant** | La PyME administra sus personas, sus categorias y el estado de las solicitudes |

Detalles que conviene conocer:

- Las solicitudes tienen **folio propio por PyME** (`SOL-00001`) y una
  **bitacora de estados** que el cliente ve.
- Los **clientes no pueden cambiar el estado** de su propia solicitud.
- El aislamiento entre Pymes lo impone la base de datos. Ver
  [03](03-MULTITENANCY.md).

---

## 2. Lo que el portal NO hace

### No emite documentos tributarios

El portal **pública** documentos que la PyME ya emitio. No los genera, no los
timbra y no se conecta a ningun proveedor de facturacion.

La tabla `documentos` tiene `tipo`, `numero`, `emitido_en`, `monto_bruto` y
`moneda`, pero eso es **describir** un documento que existe en otro sistema,
no emitirlo.

**Sobre LibreDTE, con todas las letras:** su API es de tipo **GET y esta en
beta**. **No hay ningun conector implementado en este proyecto**, y no se
deberia anunciar ninguno. Si en el futuro se integra, habria que confirmar
primero el metodo de autenticación y la disponibilidad del servicio, y hacerlo
como un modulo aparte, con sus propias pruebas.

### No hay pagos

No hay pasarela, ni cobro automático, ni conciliaracion. No estaba entre los
cinco modulos del primer corte. Si se agrega, es un modulo nuevo.

### No hay notificaciones

No se envian correos ni SMS. La invitacion a una persona se devuelve en
pantalla **una sola vez** y la PyME la entrega por el canal que quiera.

### No hay carga de documentos

La PyME no sube archivos desde la interfaz. Los documentos se registran en la
base y el archivo se deja en el volumen. La columna `ruta_archivo` esta lista
y la descarga ya valida que la ruta no se salga del volumen, pero **la pantalla
de carga no existe**.

### No hay edicion detallada de categorias

Se pueden crear categorias, verlas y activarlas o desactivarlas. **No** se
puede editar su definicion de campos desde la interfaz (la API si lo permite).

### No hay gestión de datos de la PyME

Editar el nombre, el RUT, el dominio o los contactos de una PyME es una tarea
de consola. No hay pantalla, y deberia haberla: es un dato que cambia.

### No hay pantalla de super administración

No existe una pantalla donde Hefesto vea todas las Pymes. La gestión es por
comando (`portal:crear-tenant`) y por base de datos.

### No hay recuperacion de contrasena

Las personas no tienen contrasena en el portal. Se recuperan en Authentik.

---

## 3. Decisiones pendientes

Cosas que se decidieron por ahora pero que conviene revisar con el cliente:

1. **El slug se puede enumerar antes de entrar.** Es público (aparece en la
   URL que la PyME reparte). Un atacante puede descubrir que la Panaderia Las
   Tortas usa el portal, aunque no puede ver mas que su nombre. Si no es
   aceptable, la alternativa es resolver siempre por dominio de correo y no
   pedir el slug. Ver [03](03-MULTITENANCY.md), seccion 4.

2. **La sesión de Authentik no se cierra al salir del portal.** Se eligio eso
   para no obligar a autenticarse de nuevo en cada PyME. El single sign-out
   esta disponible via `end_session_endpoint` si el cliente lo pide.

3. **La ventana de reintento de la invitacion es de 30 dias.** Es un valor
   arbitrario.

4. **El tipo de documento "factura" y "boleta" se marca como tributario** en la
   interfaz, pero eso es solo informativo. El portal no sabe si un documento es
   valido ante el SII.

---

## 4. Nombres de la interfaz

Todo el texto visible esta en espanol de Chile. El código, los nombres de
clase, los nombres de tabla y los mensajes de error también.

Se eligio **no usar tildes en los comentarios del código**. Es una convencion
del proyecto, no una regla: los mensajes de error que ve la persona si las
llevan cuando corresponde, y la documentacion las usa normalmente.

---

## 5. Lo que este primer corte no es

Este proyecto **no esta listo para producción**. Es un primer corte con:

- La arquitectura decidida y justificada por escrito.
- El código completo de la capa de datos, del aislamiento y de los cinco
  modulos.
- Pruebas escritas, **pero no ejecutadas**.
- Despliegue definido, **pero no probado**.

La lista exacta de lo que se verifico y lo que no esta en
[10 - Pruebas](10-PRUEBAS.md). Ese documento es el que hay que leer antes de
comprometer fechas.