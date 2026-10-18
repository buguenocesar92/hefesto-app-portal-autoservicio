<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de acceso: quien hizo que y desde donde.
 *
 * Se escribe en modo append-only desde el middleware AuditarAcceso.
 * No tiene interfaz de lectura en el portal: se consulta por consola
 * o por el panel del Hosting. Ver docs/08, seccion "Trazabilidad".
 *
 * AISLAMIENTO: RLS por `tenant_id`.
 */
class BitacoraAcceso extends Model
{
    use HasUuids;

    protected $table = 'bitacora_accesos';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'accion',
        'entidad',
        'entidad_id',
        'metodo_http',
        'ruta',
        'direccion_ip',
        'agente_usuario',
        'exito',
        'registrado_en',
    ];

    protected function casts(): array
    {
        return [
            'exito' => 'boolean',
            'registrado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}