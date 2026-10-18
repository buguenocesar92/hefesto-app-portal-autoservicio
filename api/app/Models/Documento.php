<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoDocumento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento emitido por la PyME y puesto a disposicion de su cliente
 * (facturas, boletas, ordenes de trabajo, guias, contratos).
 *
 * `ruta_archivo` es una ruta RELATIVA dentro del volumen de la API.
 * Nunca se guarda una ruta absoluta ni una URL: asi el volumen se puede
 * mover entre servidores sin reescribir la base de datos.
 *
 * AISLAMIENTO: RLS por `tenant_id`. El archivo en disco se sirve solo
 * despues de autorizar, nunca por ruta directa de un servidor web.
 */
class Documento extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'documentos';

    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'tipo',
        'numero',
        'detalle',
        'emitido_en',
        'monto_bruto',
        'moneda',
        'ruta_archivo',
        'nombre_archivo',
        'mime',
        'tamano_bytes',
        'hash_sha256',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumento::class,
            'emitido_en' => 'date',
            'monto_bruto' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Ruta del archivo dentro del volumen, construida de forma segura. */
    public function rutaEnDisco(): string
    {
        return $this->tenant_id . '/' . $this->ruta_archivo;
    }
}