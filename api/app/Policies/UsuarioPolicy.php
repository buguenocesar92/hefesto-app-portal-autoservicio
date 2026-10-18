<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;

/**
 * Administracion de las personas de una PyME.
 *
 * Todas las acciones son del administrador de la PyME. Aplica sobre la
 * clase `Usuario` (listar, invitar) y sobre una instancia (editar,
 * desactivar).
 *
 * El aislamiento entre Pymes no se decide aca: lo decide la RLS. Esta
 * politica solo contesta "esta persona de ESTA PyME puede administrar a
 * otra persona de la MISMA PyME".
 */
class UsuarioPolicy
{
    public function verListado(Usuario $usuario): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    public function actualizar(Usuario $usuario, Usuario $objetivo): bool
    {
        // Editar el rol de otra persona exige ser administradora o
        // administrador. La proteccion de "no dejar a la PyME sin admins"
        // esta en el controlador, porque depende del estado del conjunto.
        return $usuario->activo && $usuario->esAdministrador();
    }

    public function desactivar(Usuario $usuario, Usuario $objetivo): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }
}