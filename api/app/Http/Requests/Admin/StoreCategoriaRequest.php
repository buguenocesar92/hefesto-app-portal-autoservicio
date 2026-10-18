<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una categoria de servicio.
 *
 * `campos` es la definicion de los datos que el cliente debera entregar. Se
 * valida la ESTRUCTURA aca; que los datos que envie el cliente calcen con
 * esta definicion lo revisa SolicitudController::validarCamposDinamicos.
 */
class StoreCategoriaRequest extends FormRequest
{
    /** Tipos admitidos en un campo dinamico. */
    public const TIPOS = ['texto', 'numero', 'fecha', 'seleccion', 'booleano'];

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
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'tiempo_respuesta_sugerido' => ['nullable', 'integer', 'min:1', 'max:720'],
            'activa' => ['sometimes', 'boolean'],

            'campos' => ['nullable', 'array', 'max:20'],
            'campos.*.clave' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'campos.*.etiqueta' => ['required', 'string', 'max:120'],
            'campos.*.tipo' => ['required', Rule::in(self::TIPOS)],
            'campos.*.requerido' => ['sometimes', 'boolean'],
            'campos.*.ayuda' => ['sometimes', 'nullable', 'string', 'max:200'],
            'campos.*.opciones' => ['sometimes', 'nullable', 'array', 'max:20'],
            'campos.*.opciones.*' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Ponle un nombre a la categoria.',
            'campos.*.clave.regex' => 'La clave del campo solo puede tener letras minusculas, numeros y guion bajo.',
            'campos.*.tipo.in' => 'El tipo de campo no es valido.',
        ];
    }

    /**
     * Una seleccion sin opciones no se puede llenar nunca, asi que se
     * rechaza en el mismo paso que valida el resto.
     *
     * @param  array<string, mixed>  $datos
     */
    public function withValidator($validador): void
    {
        $validador->after(function ($validador): void {
            foreach ((array) ($this->input('campos') ?? []) as $indice => $campo) {
                if (is_array($campo) && ($campo['tipo'] ?? null) === 'seleccion') {
                    if (($campo['opciones'] ?? []) === []) {
                        $validador->errors()->add(
                            'campos.' . $indice . '.opciones',
                            'Un campo de tipo seleccion necesita al menos una opcion.'
                        );
                    }
                }
            }
        });
    }
}