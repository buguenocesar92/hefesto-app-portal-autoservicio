<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Solicitud;
use App\Models\Usuario;

/**
 * Quien puede hacer que con una solicitud de servicio.
 *
 * La RLS ya garantiza que `Solicitud` y `Usuario` son del mismo tenant. Esta
 * politica decide dentro del tenant: que ve el cliente final y que ve la
 * PyME. Son dos filtros distintos y ambos hacen falta.
 */
class SolicitudPolicy
{
    /** Cualquier usuario activo de la PyME abre solicitudes. */
    public function crear(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function verListado(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    /**
     * El cliente ve su propia solicitud; el administrador de la PyME, todas
     * las de su PyME.
     */
    public function ver(Usuario $usuario, Solicitud $solicitud): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        return $usuario->esAdministrador() || $solicitud->perteneceA($usuario);
    }

    /** Responder por el mismo canal que puede ver: el que lo creo o la PyME. */
    public function comentar(Usuario $usuario, Solicitud $solicitud): bool
    {
        return $this->ver($usuario, $solicitud);
    }

    /**
     * Cambiar el estado es de la PyME. Nunca del cliente: es la regla que
     * impide que un cliente cierre su propia orden sin que nadie la atienda.
     */
    public function cambiarEstado(Usuario $usuario, Solicitud $solicitud): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }
}