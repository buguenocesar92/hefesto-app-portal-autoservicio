<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados posibles de una solicitud de servicio.
 *
 * El flujo permitido esta centralizado en MaquinaDeEstadosSolicitud.
 * Un estado que no exista aqui no se puede asignar por la API.
 */
enum EstadoSolicitud: string
{
    case Recibida = 'recibida';
    case EnRevision = 'en_revision';
    case Agendada = 'agendada';
    case EnEjecucion = 'en_ejecucion';
    case EsperaCliente = 'espera_cliente';
    case Resuelta = 'resuelta';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recibida => 'Recibida',
            self::EnRevision => 'En revision',
            self::Agendada => 'Agendada',
            self::EnEjecucion => 'En ejecucion',
            self::EsperaCliente => 'Espera al cliente',
            self::Resuelta => 'Resuelta',
            self::Cerrada => 'Cerrada',
            self::Anulada => 'Anulada',
        };
    }

    /** Estado terminal: la solicitud ya no espera trabajo. */
    public function esTerminal(): bool
    {
        return match ($this) {
            self::Cerrada, self::Anulada => true,
            default => false,
        };
    }

    /** Si la solicitud sigue viva para el usuario (aparece en "activas"). */
    public function estaActiva(): bool
    {
        return ! $this->esTerminal();
    }
}