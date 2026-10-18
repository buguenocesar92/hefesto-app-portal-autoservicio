<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSolicitud;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de estados de una solicitud. Es la trazabilidad que la
 * PyME muestra al cliente final: "tu pedido paso por aqui".
 *
 * AISLAMIENTO: RLS por `tenant_id`.
 */
class SolicitudEvento extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'solicitud_eventos';

    // Los eventos son historico inmutable: no se actualizan ni se borran.
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'solicitud_id',
        'usuario_id',
        'estado_anterior',
        'estado_nuevo',
        'comentario',
        'registrado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado_anterior' => EstadoSolicitud::class,
            'estado_nuevo' => EstadoSolicitud::class,
            'registrado_en' => 'datetime',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(Solicitud::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}