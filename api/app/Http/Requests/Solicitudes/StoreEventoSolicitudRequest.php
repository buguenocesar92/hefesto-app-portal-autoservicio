<?php

declare(strict_types=1);

namespace App\Http\Requests\Solicitudes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Comentario de una solicitud.
 *
 * Un comentario no cambia el estado: la maquina de estados se encarga de eso,
 * y solo la PyME la mueve. Por eso aca no se acepta `estado`.
 */
class StoreEventoSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comentario' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comentario.required' => 'Escribe el comentario antes de enviarlo.',
            'comentario.min' => 'El comentario debe tener al menos 3 caracteres.',
        ];
    }
}