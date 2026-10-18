<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Categoria de servicio.
 *
 * `campos` SI se publica: es la definicion que la SPA usa para dibujar el
 * formulario de la solicitud. Se publica solo cuando la peticion lo pide con
 * `?campos=1`, porque en los listados largos pesa y no se usa.
 */
class CategoriaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'tiempo_respuesta_sugerido' => $this->tiempo_respuesta_sugerido,
            'activa' => $this->activa,
            'tiene_campos' => ($this->campos ?? []) !== [],
            'campos' => $request->boolean('campos') ? ($this->campos ?? []) : [],
        ];
    }
}