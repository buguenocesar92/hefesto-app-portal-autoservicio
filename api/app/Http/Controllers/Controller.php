<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as ControladorBase;

/**
 * Controlador base.
 *
 * El esqueleto de Laravel 11+ genera esta clase vacia. Se reescribe aqui
 * para dejar escrito de que traits dependen los controladores: el
 * `AuthorizesRequests` es el que habilita `$this->authorize(...)`, que es
 * donde vive el permiso real (las Policies), y el `ValidatesRequests` el que
 * habilita `$request->validate(...)`.
 *
 * En este esqueleto solo hay autenticacion por sesion, sin tokens de API, asi
 * que no se usa el trait `ValidatesRequests` de API ni Sanctum.
 */
abstract class Controller extends ControladorBase
{
    use AuthorizesRequests;
    use ValidatesRequests;
}