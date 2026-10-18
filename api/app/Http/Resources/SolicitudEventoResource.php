<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento de la bitacora de una solicitud (cambio de estado o comentario).
 *
 * `estado_nuevo` es obligatorio en la base, asi que un comentario se registra
 * con el estado que tenia la solicitud en ese momento y `estado_anterior` en
 * NULL. De ahi se distingue un cambio de un comentario: si `estado_anterior`
 * es NULL, no hubo cambio. Ver SolicitudController::agregarEvento.
 */
class SolicitudEventoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'solicitud_id' => $this->solicitud_id,
            'estado_anterior' => $this->estado_anterior?->value,
            'estado_nuevo' => $this->estado_nuevo->value,
            'estado_nuevo_etiqueta' => $this->estado_nuevo->etiqueta(),
            'es_cambio_de_estado' => $this->estado_anterior !== null,
            'comentario' => $this->comentario,
            'autor' => $this->whenLoaded('usuario', fn (): array => [
                'id' => $this->usuario->id,
                'nombre_completo' => $this->usuario->nombreCompleto(),
                'rol' => $this->usuario->rol->value,
            ]),
            'registrado_en' => $this->registrado_en?->toIso8601String(),
        ];
    }
}