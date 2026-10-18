# 02 - Autenticación

## 1. Dos preguntas que no son la misma

Casi todos los errores de un portal multi-cliente vienen de confundir estas
dos:

| Pregunta | Quien responde |
|---|---|
| **¿Quien es?** (identidad) | Authentik |
| **¿Que puede hacer aquí?** (permiso) | La PyME, dentro del portal |

Una persona puede estar bien identificada y no tener permiso para nada en una
PyME concreta. Y al reves: puede tener permiso en su PyME y ninguna
autoridad sobre otra.

En este portal:

- La **identidad** la demuestra Authentik con un token de identificacion (ID
  token) firmado.
- El **permiso** es el rol guardado en la tabla `usuarios` (`cliente` o
  `administrador`), que la PyME administra desde la propia interfaz.

---

## 2. El grupo de Authentik NO da permiso permanente

Se eligio usar un grupo de Authentik (`AUTHENTIK_GRUPO_ADMIN`) **solo para el
primer alta**: si la identidad pertenece a ese grupo, nace como
administrador. Despues, el rol lo administra la PyME desde el portal.

Por que no leer el grupo en cada peticion:

- El permiso tiene que poder **cambiar sin que la persona vuelva a
  autenticarse**. Si el rol viviera en Authentik, quitarle el permiso
  significaria esperar a que expire la sesión.
- La PyME deberia poder administrar su portal sin depender de que Hefesto le
  toque la configuración de Authentik.

---

## 3. El flujo completo

```
1. GET /api/v1/auth/login?slug=panaderia-las-tortas
      La API guarda en la sesion: nonce, slug, destino y una marca de tiempo.
      Socialite genera el code_verifier de PKCE y lo deja en la sesion.
      Responde: { "url": "https://id.ejemplo.cl/application/o/portal/authorize?..." }

2. El navegador navega ahi. La persona se autentica en Authentik.

3. Authentik devuelve a GET /api/v1/auth/callback?code=...&state=...

4. La API:
   a. valida `state` (Socialite lo hace y lanza excepcion si no calza)
   b. canjea el codigo por los tokens, enviando el code_verifier (PKCE)
   c. VERIFICA el ID token (ver seccion 4)
   d. resuelve a que PyME pertenece la identidad (ver seccion 5)
   e. crea o actualiza el usuario local
   f. abre la sesion de Laravel (la cookie es HttpOnly)

5. El BFF detecta que era el callback y redirige al navegador, en vez de
   devolverle un JSON.
```

**El navegador nunca ve un token.** No hay `access_token` en
`localStorage`, ni en la URL, ni en el HTML.

---

## 4. Verificación del ID token (lo que Socialite NO hace)

`laravel/socialite` v5.31.0 canjea el código y trae los datos del usuario, pero
**no verifica** el token de identificacion. Verificarlo es obligatorio: sin esa
comprobacion, la API aceptaria como valido un token emitido para otra
aplicación o por otro proveedor.

`App\Services\Auth\VerificadorIdToken` hace, **en este orden**:

1. Que el token tenga tres segmentos y venga firmado.
2. Que el algoritmo de la cabecera sea el esperado (`AUTHENTIK_ALGORITMO`,
   por defecto `RS256`). Esto evita el **ataque de confusion de algoritmo**,
   en el que alguien cambia la cabecera de `RS256` a `HS256` y firma con la
   clave pública.
3. Que la clave del JWKS corresponda al `kid` del token.
4. Que la firma sea valida (lo verifica `firebase/php-jwt`).
5. Que `iss` coincida exactamente con el emisor configurado (con barra
   final incluida).
6. Que `aud` contenga el identificador de cliente. Con varias audiencias, `azp`
   debe identificar a esta aplicación.
7. Que `nonce` sea el que se genero en **esta** sesión.
8. Que `exp` siga en vigor, con tolerancia de reloj.

El `nonce` se lee una sola vez (`pull`) y el intento de entrada tiene una
ventana de 10 minutos. Un enlace a medio abrir que alguien dejo abierto horas
no sirve para iniciar sesión a la tarde.

### Por que `email_verified` NO decide nada

Desde **Authentik 2025.10** el claim `email_verified` vale `false` salvo que
se configure explicitamente el flujo que lo verifica. Confiar en ese claim
seria confiar en algo que el proveedor ya no garantiza, así que **no se usa
como criterio**: el alta se decide por el dominio de correo del tenant o por
una invitacion vigente.

---

## 5. A que PyME pertenece quien entra

Implementado en `App\Services\Auth\ResolvedorTenant`.

**Regla 1: el slug de la URL manda.** Si la persona entro por
`portal.hefestostudio.cl/panaderia-las-tortas`, se busca esa PyME y se exige
que este activa.

**Regla 2: si no hay slug, se resuelve por dominio de correo.** Cada PyME
declara el dominio de sus empleados en `tenants.dominio_correo`, que es único
en toda la instalación.

**Regla 3: si hay slug y dominio, se cruzan.** Si el dominio del correo no es
el que declaro esa PyME, el acceso se rechaza y queda registrado. Este cruce
es lo que impide que un empleado de una PyME entre por la puerta de otra.

### El alta exige una de dos condiciones

- El dominio del correo es el dominio declarado por la PyME, o
- hay una invitacion vigente para ese correo en esa PyME.

Sin esto, cualquier identidad de Authentik con un correo cualquiera entraria al
portal de la PyME cuya URL se visito.

### Una identidad es una sola persona

`usuarios.authentik_sub` es **único en toda la instalación**, no
`(tenant_id, authentik_sub)`. Authentik tiene una identidad por persona, así
que la misma persona no puede estar inscrita en dos Pymes a la vez. En
cambio, `correo` **no** es único: un ilustrador que trabaja para dos Pymes
distintas puede tener el mismo correo en las dos.

---

## 6. Por que el proveedor OIDC es código propio

En Socialite v5.31.0 **no existe un driver OIDC generico**. En `src/Two` hay
controladores de proveedores concretos (Google, GitHub, GitLab, Slack,
LinkedIn, Twitch, X, Facebook, Bitbucket) mas la clase `AbstractProvider`.

Por eso `App\Services\Auth\ProveedorOidcAuthentik` extiende `AbstractProvider`
e implementa el flujo de código de autorización de OIDC contra los endpoints
que declara el documento de descubrimiento.

Los endpoints **no estan escritos a mano**. Se leen de
`{AUTHENTIK_ISSUER}.well-known/openid-configuration`, que se cachea en Redis:

```
OpenID Configuration  /application/o/<slug>/.well-known/openid-configuration
JWKS                   /application/o/<slug>/jwks/
End Session            /application/o/<slug>/end-session/
Authorization          /application/o/authorize/
Token                  /application/o/token/
User Info              /application/o/userinfo/
```

Si Authentik cambia una ruta, el portal no se rompe: vuelve a leer el
documento.

### PKCE

Se usa authorization code + **PKCE S256**. El flujo implicito no se usa: la
propia documentacion de Authentik lo marca como legado. El `code_verifier` lo
genera `AbstractProvider::redirect()` y lo deja en la sesión; `getCodeChallenge()`
lo hashea para el desafio, y `getTokenFields()` lo vuelve a leer al canjear.

### El detalle del `id_token`

En `AbstractProvider::user()`, la respuesta del endpoint de token se pasa a
`userInstance()`, y ahi `User::setToken()` recibe **solo el `access_token`**.
El `id_token` se queda en el arreglo de respuesta y se pierde.

Por eso `ProveedorOidcAuthentik` sobrescribe `userInstance()` para conservar el
`id_token` antes de que Socialite lo descarte. Sin eso no se podrian verificar
`iss`, `aud` ni `nonce`, que son justamente las comprobaciones que hacen
segura la entrada.

---

## 7. Cierre de sesión

`POST /api/v1/auth/logout` cierra **solo la sesión del portal**: invalida la
sesión de Laravel y regenera el token.

La sesión de Authentik **no** se cierra. Si se cerrara, la persona tendria que
autenticarse de nuevo en el proximo ingreso, para una PyME que probablemente
tiene otras.

El cierre global (single sign-out) se puede agregar despues usando el
endpoint `end_session_endpoint` que declara el documento de descubrimiento.

---

## 8. Cuando Authentik rota su clave

Si Authentik rota su clave de firma y la API sigue rechazando tokens validos
con el error *"La clave ... que firmo el token no esta en el JWKS"*:

```
php artisan portal:olvidar-cache-oidc
```

Esto descarta el documento de descubrimiento y el JWKS cacheados en Redis, y
la proxima entrada los vuelve a leer.

---

## Referencias oficiales usadas

- Proveedor OAuth2/OpenID Connect de Authentik:
  <https://docs.goauthentik.io/add-secure-apps/providers/oauth2/>
- Socialite v5.31.0 (`src/Two`):
  <https://github.com/laravel/socialite/tree/v5.31.0/src/Two>
- `firebase/php-jwt` v7.2.1:
  <https://github.com/firebase/php-jwt/blob/v7.2.1/src/JWT.php>