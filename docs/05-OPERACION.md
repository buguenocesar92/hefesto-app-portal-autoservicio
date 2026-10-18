# 05 - Operación

Manual de tareas del dia a dia. Todo lo que aparece aquí es una accion
concreta que hace falta para que el portal siga sirviendo.

---

## 1. Dar de alta una PyME

Solo lo hace Hefesto (quien administra la instalación).

```bash
php artisan portal:crear-tenant nombre-de-la-pyme \
  --nombre="Nombre Comercial SpA" \
  --razon-social="Nombre Comercial SpA" \
  --rut="76.123.456-7" \
  --dominio=dominio.cl \
  --correo-contacto=contacto@dominio.cl
```

El `slug` es el identificador que aparece en la URL de entrada. El se normaliza
a minusculas y sin acentos.

Opciones utiles:

- `--inactivo`: crea la PyME desactivada. No puede entrar al portal hasta
  activarla.
- Sin `--dominio`: el acceso solo funciona entrando por el slug, porque no hay
  forma de resolver el tenant por correo.

### Cambiar los datos de una PyME

No hay comando ni pantalla todavia (queda pendiente). De momento, con un editor
SQL, **con cuidado**, y siempre respaldando antes.

---

## 2. Invitar a una persona

La PyME lo hace desde el portal: **Personas -> Invitar**.

1. Se escribe el correo y el rol.
2. La API genera un token de 48 caracteres.
3. Se guarda **solo su hash SHA-256**. El token en claro no se persiste.
4. El token se muestra **una sola vez**.

Si se pierde, se genera otra invitacion. No hay forma de recuperar el token
anterior, y eso es a propósito: si se pudiera recuperar, un vuelco de la base
de datos entregaria invitaciones usables.

> El portal **no envia correos** en este primer corte. La entrega del token es
> responsabilidad de la PyME.

---

## 3. Cambiar el rol de una persona

Desde el portal, en la misma pantalla de **Personas**. Dos reglas impiden
dejar a la PyME sin administración:

- **No se puede quitar el rol de administrador a la última administradora o
  administrador.**
- **Uno no puede desactivarse a si mismo.** Si lo hiciera, la PyME podria
  quedar inaccesible.

En ambos casos la API responde 422 con el motivo.

---

## 4. Desactivar a alguien

Desde **Personas -> Desactivar**.

Desactivar **no borra**: la persona deja de entrar al portal, pero sus
solicitudes y documentos conservan su nombre, y el historial sigue siendo
real. Borrar personas romperia la trazabilidad.

El siguiente ingreso de esa identidad se rechaza con un mensaje explicito, y
queda registrado en el canal de seguridad.

---

## 5. Respaldar

Ver [07 - Despliegue](07-DESPLIEGUE.md), seccion "Respaldos". En corto:

```bash
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_dump -U portal_app portal_autoservicio > respaldo-$(date +%F).sql
```

El volumen de documentos se respalda aparte.

---

## 6. Mantenimiento periodico

```bash
# Sesiones vencidas
php artisan session:table prune

# Lotes viejos de la cola
php artisan queue:prune-batches --hours=168

# Cache de Laravel
php artisan cache:clear
```

Los tres estan programados en `routes/console.php`.

---

## 7. Problemas frecuentes

### "No hay contexto de tenant en la conexión"

Error de la función `app_tenant_id()`. Significa que una consulta a una tabla
protegida se ejecuto fuera del middleware `ResolverTenant`, o fuera de la
transacción.

**Por que aparece en un log y no como pantalla**: es a propósito. Un error
visible seria preferible a devolver datos equivocados, pero lo importante es
que **falla cerrado**: la operación no se completa.

Causas habituales:

- Un comando de consola o un job de cola que consulta una tabla protegida sin
  fijar el contexto. La solucion es usar `ContextoTenant::ejecutarAislado()`.
- Un seeder que inserta directo. Los seeders de este proyecto ya lo hacen
  correctamente.

### "La clave ... que firmo el token no esta en el JWKS"

Authentik roto su clave de firma y la API tiene la anterior en cache.

```bash
php artisan portal:olvidar-cache-oidc
```

### "Este correo no pertenece a ninguna PyME con acceso al portal"

Ninguna PyME declara ese dominio y no hay invitacion vigente. Opciones:

- La PyME debe declarar `--dominio` con el dominio correcto, o
- hay que crear una invitacion para ese correo.

### "Esta cuenta no tiene acceso al portal de esa PyME"

Cruce rechazado: la persona entro por el slug de una PyME, pero su correo no
corresponde al dominio que esa PyME declaro. Esta es una protección, no un
error de configuración. Queda registrado.

### "La session store is not defined" / errores raros de sesión

La sesión esta en la base de datos: la tabla `sessions` tiene que existir.

```bash
php artisan migrate:status
php artisan migrate
```

### El portal responde 502 en todas las peticiones

La API no esta levantando. Revisar:

```bash
docker compose -f deploy/docker-compose.dev.yml logs api
docker compose -f deploy/docker-compose.dev.yml ps
```

El `entrypoint` del contenedor ya espera a PostgreSQL y falla con un mensaje
claro si `APP_KEY` no esta definida.

---

## 8. Registrar un problema

Antes de escalar, mirar en este orden:

1. `docker compose logs api` (errores de la API)
2. El canal de seguridad: los hechos previos al login van a
   `portal_seguridad`, que escribe en JSON por `stderr`
3. `php artisan portal:verificar-aislamiento` (el aislamiento sigue en pie?)