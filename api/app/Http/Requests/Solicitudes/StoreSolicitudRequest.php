<?php

declare(strict_types=1);

namespace App\Http\Requests\Solicitudes;

use App\Enums\PrioridadSolicitud;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una solicitud de servicio.
 *
 * La validacion de `categoria_id` usa `exists` a proposito: la consulta de
 * existencia corre dentro de la transaccion abierta por el middleware
 * ResolverTenant, asi que la politica RLS la aplica y un identificador de
 * otra PyME se comporta como inexistente (error de validacion, no datos).
 *
 * El contenido de `datos` NO se valida aqui, sino contra la definicion de
 * campos de la categoria: ver SolicitudController::validarCamposDinamicos.
 */
class StoreSolicitudRequest extends FormRequest
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
            'categoria_id' => [
                'nullable',
                'uuid',
                Rule::exists('categorias_servicio', 'id')->where('activa', true),
            ],
            'titulo' => ['required', 'string', 'min:5', 'max:160'],
            'descripcion' => ['required', 'string', 'min:10', 'max:5000'],
            'prioridad' => ['required', Rule::enum(PrioridadSolicitud::class)],
            'datos' => ['nullable', 'array'],
            'datos.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titulo.required' => 'Ponle un titulo breve a tu solicitud.',
            'titulo.min' => 'El titulo debe tener al menos 5 caracteres.',
            'descripcion.required' => 'Describe el problema para que la PyME pueda ayudarte.',
            'descripcion.min' => 'La descripcion debe tener al menos 10 caracteres.',
            'prioridad.in' => 'La prioridad no es valida.',
            'categoria_id.exists' => 'Esa categoria de servicio no existe.',
            'datos.array' => 'Los datos de la solicitud deben ir como objeto.',
        ];
    }
}