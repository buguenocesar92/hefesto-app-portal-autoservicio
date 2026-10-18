<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RolUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitacion de una persona a la PyME.
 *
 * Solo crea una invitacion: no crea la persona en Authentik. El correo es
 * obligatorio y se guarda en minusculas, que es como lo entrega el
 * proveedor de identidad.
 */
class StoreInvitacionRequest extends FormRequest
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
            'correo' => ['required', 'email:rfc', 'max:190'],
            'rol' => ['sometimes', Rule::enum(RolUsuario::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'correo.required' => 'Indica el correo de la persona que quieres invitar.',
            'correo.email' => 'El correo no tiene un formato valido.',
        ];
    }
}