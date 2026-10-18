<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EstadoSolicitud;
use App\Services\Solicitudes\MaquinaDeEstadosSolicitud;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representacion de una solicitud de servicio.
 *
 * No incluye `tenant_id`: para el cliente final no tiene sentido y Publico es
 * informacion que solo necesita el servidor. Cuantas mas columnas digitales,
 * menos cosas hay que revisar antes de tocar este recurso.
 */
class SolicitudResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'es_terminal' => $this->estado->esTerminal(),
            'prioridad' => $this->prioridad->value,
            'prioridad_etiqueta' => $this->prioridad->etiqueta(),
            'datos' => (object) ($this->datos ?? []),
            'categoria' => $this->whenLoaded('categoria', fn (): array => [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ]),
            'solicitante' => $this->whenLoaded('usuario', fn (): array => [
                'id' => $this->usuario->id,
                'nombre_completo' => $this->usuario->nombreCompleto(),
                'correo' => $this->usuario->correo,
            ]),
            'asignado_a' => $this->asignado_a,
            'cerrada_en' => $this->cerrada_en?->toIso8601String(),
            'creado_en' => $this->created_at?->toIso8601String(),
            'actualizado_en' => $this->updated_at?->toIso8601String(),
            'estados_siguientes' => $this->estadosSiguientes(),
        ];
    }

    /**
     * Estados a los que la PyME puede llevar esta solicitud desde aqui.
     *
     * Solo le sirve a quien administra: el cliente final nunca cambia el
     * estado, asi que la lista le llega vacia de todas formas.
     *
     * @return list<string>
     */
    private function estadosSiguientes(): array
    {
        if (! $this->estado instanceof EstadoSolicitud) {
            return [];
        }

        return array_map(
            static fn (EstadoSolicitud $estado): string => $estado->value,
            app(MaquinaDeEstadosSolicitud::class)->siguientes($this->estado)
        );
    }
}