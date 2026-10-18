<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * Edicion de una categoria de servicio.
 *
 * Hereda toda la validacion de la alta (`StoreCategoriaRequest`) y le cambia
 * el uso de `sometimes`: aca todo es opcional, porque es un PATCH parcial.
 */
class ActualizarCategoriaRequest extends StoreCategoriaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = parent::rules();

        foreach (['nombre', 'tiempo_respuesta_sugerido', 'campos'] as $clave) {
            unset($reglas[$clave]);
        }

        // `campos` sigue validandose cuando viene, pero como algo opcional
        // en la raiz.
        $reglas['campos'] = ['sometimes', 'nullable', 'array', 'max:20'];
        $reglas['campos.*.clave'] = ['required_with:campos', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'];
        $reglas['campos.*.etiqueta'] = ['required_with:campos', 'string', 'max:120'];
        $reglas['campos.*.tipo'] = ['required_with:campos', Rule::in(self::TIPOS)];
        $reglas['campos.*.requerido'] = ['sometimes', 'boolean'];
        $reglas['campos.*.ayuda'] = ['sometimes', 'nullable', 'string', 'max:200'];
        $reglas['campos.*.opciones'] = ['sometimes', 'nullable', 'array', 'max:20'];
        $reglas['campos.*.opciones.*'] = ['required', 'string', 'max:80'];

        return $reglas;
    }
}