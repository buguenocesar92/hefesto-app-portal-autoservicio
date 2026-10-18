<?php

declare(strict_types=1);

/**
 * Configuracion del proveedor de identidad Authentik (OIDC).
 *
 * Los endpoints NO se escriben a mano: se descubren desde el documento
 * de descubrimiento OpenID Connect que publica Authentik en
 *
 *   {AUTHENTIK_ISSUER}.well-known/openid-configuration
 *
 * Con el modo de emisor por proveedor (el predeterminado de Authentik),
 * el emisor incluye el slug de la aplicacion:
 *
 *   https://id.ejemplo.cl/application/o/portal/
 *
 * Verificado contra la documentacion oficial de Authentik:
 * https://docs.goauthentik.io/add-secure-apps/providers/oauth2/
 */
return [
    /*
    |---------------------------------------------------------------------------
    | Emisor (issuer)
    |---------------------------------------------------------------------------
    | Debe coincidir EXACTAMENTE con el claim `iss` del token de identificacion
    | que entrega Authentik, incluida la barra final. Un `iss` distinto hace
    | fallar la verificacion del token a proposito: es la proteccion contra
    | que alguien entregue tokens de otro proveedor.
    */
    'issuer' => env('AUTHENTIK_ISSUER'),

    'client_id' => env('AUTHENTIK_CLIENT_ID'),

    'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),

    /*
    | URL de retorno. Debe estar registrada exactamente en el campo
    | "Redirect URIs" del proveedor OAuth2 de Authentik.
    */
    'redirect' => env('AUTHENTIK_REDIRECT_URI'),

    /*
    | Alcances solicitados. `openid` es obligatorio para OIDC. `profile` entrega
    | nombre y pertenencia a grupos; `email` entrega el correo.
    */
    'scopes' => array_values(array_filter(explode(
        ' ',
        (string) env('AUTHENTIK_SCOPES', 'openid profile email')
    ))),

    /*
    | Grupo de Authentik cuyos miembros entran como administradores de su
    | PyME. La pertenencia llega en el claim `groups` (alcance `profile`).
    | Si el IdP no entrega ese claim, el usuario entra siempre como cliente.
    */
    'grupo_administradores' => env('AUTHENTIK_GRUPO_ADMIN', 'portal-administradores'),

    /*
    | Algoritmo de firma exigido para el token de identificacion. Authentik
    | usa HS256 con el secreto del cliente si el proveedor no tiene una clave
    | de firma, y RS256/ES256 si se le configura una. Con RS256 el cliente
    | verifica contra el JWKS publico.
    */
    'algoritmo' => env('AUTHENTIK_ALGORITMO', 'RS256'),

    /*
    | Tiempo maximo de vida del documento de descubrimiento en cache (Redis).
    */
    'cache_segundos' => (int) env('AUTHENTIK_CACHE_SEGUNDOS', 3600),

    /*
    | Minutos de tolerancia para la expiracion del token. Authentik y la API
    | pueden tener relojes levemente distintos; 2 minutos es el margen usual.
    */
    'tolerancia_minutos' => (int) env('AUTHENTIK_TOLERANCIA_MINUTOS', 2),
];