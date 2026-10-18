<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|-------------------------------------------------------------------------------
| Sesion
|-------------------------------------------------------------------------------
|
| Reemplaza al archivo del esqueleto. Lo que cambia y POR QUE:
|
| 1. `driver` = database. La API corre en varios contenedores php-fpm. Con el
|    controlador de archivos, el usuario pierde la sesion cada vez que otra
|    replica lo atiende. Ver docs/01, seccion "Por que la sesion vive en
|    PostgreSQL".
|
| 2. Cookie HttpOnly + SameSite=Lax. El token de sesion no lo puede leer
|    JavaScript de la pagina. `lax` (y no `strict`) porque el usuario llega
|    desde Authentik por una redireccion externa: con `strict` la cookie no
|    viajaria en esa primera peticion y el usuario entraria y saliera solo.
|    Ver docs/08, seccion "Cookies".
|
| 3. `secure` depende del entorno, pero en produccion DEBE ser true: sin
|    `Secure`, la cookie de sesion viaja en texto plano por HTTP.
|
| 4. `partitioned` false: la cookie de sesion no debe quedar "particionada",
|    porque el portal no usa iframes de terceros.
|
*/

return [
    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', false),

    'encrypt' => (bool) env('SESSION_ENCRYPT', false),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'portal'), '_') . '_session'
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => (bool) env('SESSION_HTTP_ONLY', true),

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => (bool) env('SESSION_PARTITIONED_COOKIE', false),
];