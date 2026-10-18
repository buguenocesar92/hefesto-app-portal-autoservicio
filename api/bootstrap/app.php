<?php

use App\Http\Middleware\AuditarAcceso;
use App\Http\Middleware\ResolverTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Punto de arranque de la API del Portal de Autoservicio.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto
 * (artisan, public/index.php, storage, etc.) lo genera Composer.
 * Ver docs/04-INSTALACION.md, paso 2.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF y sesion se aplican por el grupo `web` de Laravel.
        // El portal es una SPA servida por Nuxt (BFF), por lo que la
        // cookie de sesion viaja en la misma origen y el token CSRF
        // se reenvia como cabecera X-XSRF-TOKEN. Ver docs/08.
        $middleware->web(append: [
            ResolverTenant::class,
            AuditarAcceso::class,
        ]);

        // Todo lo que cuelgue de /api/v1 se autentica. No hay endpoints
        // publicos de datos de negocio: solo el login, el callback y la
        // salud son accesibles sin sesion.
        $middleware->alias([
            'tenant.resuelto' => ResolverTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un error de autorizacion entre tenants no debe revelar si el
        // recurso existe en otro tenant. Se responde 404, no 403.
        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });
    })
    ->create();