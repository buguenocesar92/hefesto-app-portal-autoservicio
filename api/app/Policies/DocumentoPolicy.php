<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Documento;
use App\Models\Usuario;

/**
 * Quien puede ver y descargar un documento.
 */
class DocumentoPolicy
{
    public function verListado(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    /**
     * Un documento sin `usuario_id` es de toda la PyME. Uno con
     * `usuario_id` es de esa persona concreta.
     */
    public function descargar(Usuario $usuario, Documento $documento): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        if ($usuario->esAdministrador()) {
            return true;
        }

        return $documento->usuario_id === null || $documento->usuario_id === $usuario->id;
    }
}