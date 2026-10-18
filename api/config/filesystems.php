<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Sistema de archivos
|-------------------------------------------------------------------------------
|
| Reemplaza al archivo del esqueleto. Lo importante es el disco
| `documentos`: es un volumen SEPARADO del codigo, para que un documento de
| una PyME no termine mezclado con los archivos de la aplicacion ni se
| publique por error como contenido estatico.
|
| En desarrollo apunta a storage/app/documentos. En produccion se monta como
| volumen de Docker (ver deploy/docker-compose.prod.yml). El path se puede
| mover con la variable DOCUMENTOS_PATH sin tocar la base de datos: la tabla
| `documentos` guarda rutas RELATIVAS a la raiz del disco (ver
| App\Models\Documento::rutaEnDisco).
|
*/

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |-----------------------------------------------------------------------
        | Documentos de las Pymes
        |-----------------------------------------------------------------------
        | `throw => false`: si el archivo no esta, la API responde 404 con un
        | mensaje claro en vez de reventar con una excepcion del sistema de
        | archivos.
        */
        'documentos' => [
            'driver' => 'local',
            'root' => env('DOCUMENTOS_PATH', storage_path('app/documentos')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],
    ],

    'links' => [],
];