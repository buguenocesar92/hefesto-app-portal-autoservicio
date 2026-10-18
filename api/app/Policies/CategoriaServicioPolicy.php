<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CategoriaServicio;
use App\Models\Usuario;

/**
 * Permisos sobre las categorias de servicio.
 *
 * Son dos habilidades distintas y separadas a proposito:
 *
 *  - `verListado`: lo puede hacer cualquier usuario activo. Es lo que usa el
 *    cliente final para elegir en que categoria abre su solicitud, y solo
 *    devuelve las categorias ACTIVAS.
 *  - `administrar`: lo puede hacer el administrador de la PyME. Es lo que
 *    devuelve tambien las inactivas y las que se pueden editar.
 *
 * Si se mezclaran en una sola habilidad, un cliente que adivinara la ruta
 * `GET /api/v1/admin/categorias` veria las categorias dadas de baja.
 */
class CategoriaServicioPolicy
{
    public function verListado(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function administrar(Usuario $usuario): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    public function actualizar(Usuario $usuario, CategoriaServicio $categoria): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }
}