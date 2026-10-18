<?php

declare(strict_types=1);

namespace App\Enums;

/** Prioridad declarada por el cliente al abrir la solicitud. */
enum PrioridadSolicitud: string
{
    case Baja = 'baja';
    case Normal = 'normal';
    case Alta = 'alta';
    case Urgente = 'urgente';

    public function etiqueta(): string
    {
        return ucfirst($this->value);
    }
}