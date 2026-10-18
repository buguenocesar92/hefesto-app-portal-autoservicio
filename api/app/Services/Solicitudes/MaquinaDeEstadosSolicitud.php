<?php

declare(strict_types=1);

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitud;
use RuntimeException;

/**
 * Maquina de estados de las solicitudes de servicio.
 *
 * Aqui se decide que transiciones existen. Las transiciones NO se
 * calculan en el controlador: asi la regla queda en un solo lugar y es
 * verificable con pruebas unitarias.
 *
 * El cliente final NUNCA cambia el estado: solo crea la solicitud y
 * comenta. El estado lo mueve el administrador de la PyME. Esa
 * separacion es la que evita que un cliente cierre su propia orden por
 * error.
 */
class MaquinaDeEstadosSolicitud
{
    /**
     * Transiciones permitidas por el administrador de la PyME.
     *
     * @var array<string, list<string>>
     */
    private const TRANSICIONES = [
        'recibida' => ['en_revision', 'agendada', 'anulada'],
        'en_revision' => ['agendada', 'espera_cliente', 'anulada'],
        'agendada' => ['en_ejecucion', 'espera_cliente', 'anulada'],
        'en_ejecucion' => ['resuelta', 'espera_cliente', 'anulada'],
        'espera_cliente' => ['en_revision', 'agendada', 'en_ejecucion', 'anulada'],
        'resuelta' => ['cerrada', 'espera_cliente'],
        'cerrada' => [],
        'anulada' => [],
    ];

    /** @return list<EstadoSolicitud> */
    public function siguientes(EstadoSolicitud $actual): array
    {
        return array_map(
            static fn (string $valor): EstadoSolicitud => EstadoSolicitud::from($valor),
            self::TRANSICIONES[$actual->value] ?? []
        );
    }

    public function puedeTransicionar(EstadoSolicitud $desde, EstadoSolicitud $hacia): bool
    {
        return in_array($hacia->value, self::TRANSICIONES[$desde->value] ?? [], true);
    }

    /**
     * @throws RuntimeException si la transicion no existe.
     */
    public function exigirTransicion(EstadoSolicitud $desde, EstadoSolicitud $hacia): void
    {
        if (! $this->puedeTransicionar($desde, $hacia)) {
            throw new RuntimeException(sprintf(
                'No se puede pasar de "%s" a "%s".',
                $desde->etiqueta(),
                $hacia->etiqueta()
            ));
        }
    }

    /**
     * Estados que el cliente ve en el filtro "activas" de su historial.
     *
     * @return list<EstadoSolicitud>
     */
    public function estadosActivos(): array
    {
        return array_values(array_filter(
            EstadoSolicitud::cases(),
            static fn (EstadoSolicitud $estado): bool => $estado->estaActiva()
        ));
    }

    /**
     * @return array<string, string> estado => etiqueta
     */
    public function catalogo(): array
    {
        $catalogo = [];

        foreach (EstadoSolicitud::cases() as $estado) {
            $catalogo[$estado->value] = $estado->etiqueta();
        }

        return $catalogo;
    }
}