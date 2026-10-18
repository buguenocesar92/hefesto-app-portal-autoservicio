<?php

declare(strict_types=1);

namespace App\Http\Requests\Solicitudes;

use App\Enums\EstadoSolicitud;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado de una solicitud. Solo el administrador de la PyME.
 *
 * Que la transicion sea VALIDA (por ejemplo, no se puede reabrir una
 * solicitud cerrada) no se comprueba aca sino en
 * MaquinaDeEstadosSolicitud: las reglas del flujo viven en un solo lugar.
 *
 * `asignado_a` se valida con `exists` sobre `usuarios`: gracias a la RLS, un
 * identificador de usuario de otra PyME se comporta como inexistente.
 */
class CambiarEstadoSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(EstadoSolicitud::class)],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'asignado_a' => ['nullable', 'uuid', Rule::exists('usuarios', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Indica el estado nuevo.',
            'estado.in' => 'Ese estado no existe.',
            'asignado_a.exists' => 'Ese usuario no existe en esta PyME.',
        ];
    }
}