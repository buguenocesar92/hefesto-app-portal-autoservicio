<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CategoriaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Rutas de la API del Portal de Autoservicio
|-------------------------------------------------------------------------------
|
| Todas cuelgan del grupo `web` de Laravel (ver bootstrap/app.php), asi que
| comparten sesion, cookie y CSRF con el resto de la aplicacion. El prefijo
| `/api/v1` se aplica aqui y no en el esqueleto, para que quede a la vista
| que TODA la superficie de la API esta versionada en una sola linea.
|
| Estas rutas las consume el BFF de Nuxt (web/server/api), que reenvia las
| cookies de la peticion del navegador. Ver docs/01, seccion "Arquitectura BFF".
|
| El middleware `auth` exige sesion. Sin sesion responde 401 en formato JSON
| siempre que la peticion envie `Accept: application/json`, que es lo que
| hace el BFF. Ver docs/09, seccion "Convenciones de la API".
|
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    /*
    |---------------------------------------------------------------------------
    | Autenticacion (OIDC contra Authentik)
    |---------------------------------------------------------------------------
    | `login` y `callback` son publicos: son justamente el camino por el que se
    | entra. `logout` y `usuario` exigen sesion.
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('login', [AuthController::class, 'login'])->name('login');
        Route::get('callback', [AuthController::class, 'callback'])->name('callback');

        Route::middleware('auth')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('usuario', [AuthController::class, 'usuario'])->name('usuario');
        });
    });

    /*
    |---------------------------------------------------------------------------
    | Modulo central: solicitudes de servicio
    |---------------------------------------------------------------------------
    */
    Route::middleware('auth')->group(function (): void {

        // Categorias activas: el cliente las necesita para abrir una
        // solicitud. Solo las activas de SU PyME (la RLS ya lo garantiza).
        Route::get('categorias', [CategoriaController::class, 'indexPublico'])
            ->name('categorias.index');

        Route::apiResource('solicitudes', SolicitudController::class)
            ->only(['index', 'store', 'show'])
            ->parameters(['solicitudes' => 'solicitud'])
            ->names('solicitudes');

        Route::get('solicitudes/{solicitud}/eventos', [SolicitudController::class, 'eventos'])
            ->name('solicitudes.eventos.index');
        Route::post('solicitudes/{solicitud}/eventos', [SolicitudController::class, 'agregarEvento'])
            ->name('solicitudes.eventos.store');
        Route::patch('solicitudes/{solicitud}/estado', [SolicitudController::class, 'cambiarEstado'])
            ->name('solicitudes.estado');

        Route::get('documentos', [DocumentoController::class, 'index'])->name('documentos.index');
        Route::get('documentos/{documento}/descargar', [DocumentoController::class, 'descargar'])
            ->name('documentos.descargar');

        Route::get('historial', [HistorialController::class, 'index'])->name('historial.index');

        /*
        |-----------------------------------------------------------------------
        | Administracion del tenant, hecha por la PyME
        |-----------------------------------------------------------------------
        | El control fino (que puede hacer cada rol) vive en las Policies, no
        | aca. Aca solo se declara quien es administrador de la PyME.
        */
        Route::prefix('admin')->name('admin.')->group(function (): void {
            Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
            Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
            Route::patch('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
            Route::post('usuarios/{usuario}/desactivar', [UsuarioController::class, 'desactivar'])
                ->name('usuarios.desactivar');

            Route::get('categorias', [CategoriaController::class, 'index'])->name('categorias.index');
            Route::post('categorias', [CategoriaController::class, 'store'])->name('categorias.store');
            Route::patch('categorias/{categoria}', [CategoriaController::class, 'update'])
                ->name('categorias.update');
        });
    });
});