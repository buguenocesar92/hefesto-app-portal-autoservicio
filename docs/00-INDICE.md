# Documentacion del Portal de Autoservicio

Aplicación propia de **Hefesto Studio** para que los clientes finales de una
PyME puedan pedir una atención, descargar documentos y ver el historial, sin
llamar por telefono.

Este proyecto es software original de Hefesto. **No** es un kit de despliegue
de una herramienta de terceros ni un "portal con el logo de Hefesto".

---

## Como leer esta documentacion

| Documento | De que trata |
|---|---|
| [01 - Arquitectura](01-ARQUITECTURA.md) | Las piezas del sistema y por que estan separadas así |
| [02 - Autenticación](02-AUTENTICACION.md) | OIDC contra Authentik, y como se decide a que PyME pertenece quien entra |
| [03 - Multi-tenancy](03-MULTITENANCY.md) | **El documento central.** Aislamiento entre Pymes: por que columna + RLS y no esquema por cliente |
| [04 - Instalación](04-INSTALACION.md) | Paso a paso para levantar el portal en una maquina |
| [05 - Operación](05-OPERACION.md) | Tareas del dia a dia: altas, invitaciones, errores frecuentes |
| [06 - API](06-API.md) | Endpoints, convenciones de respuesta y de errores |
| [07 - Despliegue](07-DESPLIEGUE.md) | Producción, respaldos, versionado de imagenes, escalado |
| [08 - Seguridad](08-SEGURIDAD.md) | CSRF, cookies, bitacora, amenazas consideradas |
| [09 - Alcance](09-ALCANCE.md) | Lo que el portal hace y lo que **no** hace |
| [10 - Pruebas](10-PRUEBAS.md) | Que se ejecuto de verdad y que quedo sin probar |

---

## Estado honesto de este repositorio

Este es el **primer corte** del portal. Hay código escrito y documentado, pero
**nada de esto se ha ejecutado todavia**: no se corrieron las migraciones, ni
las pruebas, ni una construccion del frontend. El detalle exacto de lo que si
se verifico esta en [10 - Pruebas](10-PRUEBAS.md), y conviene leerlo antes de
prometerle algo a un cliente.

En concreto, **no** se puede decir que esto este listo para producción. Es un
primer corte con la arquitectura decidida y justificada por escrito.

---

## Las cuatro decisiones que explican todo lo demas

1. **Aislamiento por columna `tenant_id` + Row Level Security de PostgreSQL.**
   La base de datos, no solo el código, es la que impide que una PyME vea los
   datos de otra. Ver [03](03-MULTITENANCY.md).

2. **Laravel es la autoridad de la sesión; Nuxt es un BFF.** El navegador nunca
   habla directo con la API. Ver [01](01-ARQUITECTURA.md).

3. **La identidad la decide Authentik; el permiso lo decide la PyME.** Un grupo
   de Authentik no da permisos permanentes. Ver [02](02-AUTENTICACION.md).

4. **La tabla `tenants` NO lleva RLS**, a propósito, porque se consulta durante
   el login. Es el punto ciego del diseno y esta documentado como tal en
   [03](03-MULTITENANCY.md).