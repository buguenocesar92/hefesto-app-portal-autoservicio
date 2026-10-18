<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representacion publica de un usuario.
 *
 * El `authentik_sub` NUNCA se expone: es el identificador interno de la
 * persona en Authentik y no aporta nada en la interfaz. El modelo ademas lo
 * marca como oculto, pero aqui se listan las columnas a mano para que
 * agregar una columna a la tabla no publique un dato nuevo por accidente.
 */
class UsuarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'correo' => $this->correo,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'nombre_completo' => $this->nombreCompleto(),
            'telefono' => $this->telefono,
            'rol' => $this->rol->value,
            'rol_etiqueta' => $this->rol->etiqueta(),
            'es_administrador' => $this->esAdministrador(),
            'activo' => $this->activo,
            'ultimo_acceso' => $this->ultimo_acceso?->toIso8601String(),
            'creado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}