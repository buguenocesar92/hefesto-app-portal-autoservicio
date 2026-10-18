<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;

/*
|-------------------------------------------------------------------------------
| Registros (logs)
|-------------------------------------------------------------------------------
|
| Reemplaza al archivo del esqueleto. El canal `portal_seguridad` es
| obligatorio para el portal: lo usa App\Services\Tenancy\AuditorDeAcceso para
| los hechos que ocurren ANTES del login, que no tienen tenant y por eso no
| pueden escribirse en la tabla `bitacora_accesos` (la politica RLS exige un
| tenant de sesion). Ver docs/08, seccion "Trazabilidad".
|
| Ese canal va a `stderr` a proposito: en Docker, stderr es lo que recoge el
| recolector de logs del contenedor, sin escribir archivos dentro del
| contenedor.
|
*/

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => (bool) env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'replace_placeholders' => true,
        ],

        /*
        |-----------------------------------------------------------------------
        | Seguridad: hechos previos al login y a la sesion.
        |-----------------------------------------------------------------------
        | Se separa del log general a proposito: es la fuente que hay que
        | revisar cuando alguien reporta un acceso indebido. Nunca debe
        | contener el token de sesion ni el codigo de autorizacion.
        */
        'portal_seguridad' => [
            'driver' => 'monolog',
            'level' => env('LOG_PORTAL_SEGURIDAD', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],
];