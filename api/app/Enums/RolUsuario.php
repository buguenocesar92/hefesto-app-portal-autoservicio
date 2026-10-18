<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol del usuario final dentro de su PyME.
 *
 * `cliente`: el usuario final que compra. Ve y crea lo suyo.
 * `administrador`: las personas de la PyME que administran el portal
 *                 (alta de usuarios, categorias y estado de solicitudes).
 */
enum RolUsuario: string
{
    case Cliente = 'cliente';
    case Administrador = 'administrador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cliente => 'Cliente',
            self::Administrador => 'Administrador de la PyME',
        };
    }
}