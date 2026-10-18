<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RolUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edicion de un usuario de la PyME.
 *
 * El correo NO se puede cambiar desde aca: es el vinculo con Authentik y
 * cambiarlo a mano dejaria a la persona con una identidad que ya no
 * corresponde. Para cambiarlo hay que dar de alta la cuenta nueva en
 * Authentik. Ver docs/02.
 */
class ActualizarUsuarioRequest extends FormRequest
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
            'rol' => ['sometimes', Rule::enum(RolUsuario::class)],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rol.in' => 'El rol no es valido.',
            'telefono.max' => 'El telefono es demasiado largo.',
        ];
    }
}