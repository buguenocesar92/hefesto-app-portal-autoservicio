<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Documento disponible para descarga.
 *
 * NO se publica `ruta_archivo`: es una ruta interna del volumen y no tiene
 * que salir de la API. El cliente recibe un identificador y la URL de
 * descarga, que ademas vuelve a autorizar antes de servir el archivo.
 */
class DocumentoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'tipo_etiqueta' => $this->tipo->etiqueta(),
            'es_tributario' => $this->tipo->esTributario(),
            'numero' => $this->numero,
            'detalle' => $this->detalle,
            'emitido_en' => $this->emitido_en?->toDateString(),
            'monto_bruto' => $this->monto_bruto,
            'moneda' => $this->moneda,
            'nombre_archivo' => $this->nombre_archivo,
            'mime' => $this->mime,
            'tamano_bytes' => $this->tamano_bytes,
            'es_dirigido' => $this->usuario_id !== null,
            'url_descarga' => route('api.v1.documentos.descargar', ['documento' => $this->id], false),
            'creado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}