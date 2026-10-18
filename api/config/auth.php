<?php

declare(strict_types=1);

use App\Models\Usuario;

/*
|-------------------------------------------------------------------------------
| Autenticacion
|-------------------------------------------------------------------------------
|
| Este archivo reemplaza al que genera el esqueleto de Laravel. La diferencia
| importante es que el modelo de usuario es App\Models\Usuario (no App\Models\User)
| y que el guard web usa la SESION, no tokens: la sesion vive en la tabla
| `sessions` porque la API corre en mas de un contenedor.
|
| NO hay broker de contrasenas a proposito. Las personas no tienen contrasena
| en este portal: se autentican contra Authentik, que es la unica fuente de
| identidad y de contrasenas. Por eso `'passwords'` va vacio y no existe
| recuperacion de contrasena en el portal.
|
*/

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'usuarios'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'usuarios',
        ],
    ],

    'providers' => [
        'usuarios' => [
            'driver' => 'eloquent',
            'model' => Usuario::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),
];