<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSolicitud;
use App\Enums\PrioridadSolicitud;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de servicio creada por el cliente final. Es el modulo
 * central del portal: cada llamada telefonica que se evita nace aqui.
 *
 * AISLAMIENTO: RLS por `tenant_id`.
 */
class Solicitud extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'solicitudes';

    protected $fillable = [
        'tenant_id',
        'folio',
        'usuario_id',
        'categoria_id',
        'titulo',
        'descripcion',
        'estado',
        'prioridad',
        'datos',
        'asignado_a',
        'cerrada_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoSolicitud::class,
            'prioridad' => PrioridadSolicitud::class,
            'datos' => 'array',
            'cerrada_en' => 'datetime',
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

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaServicio::class, 'categoria_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(SolicitudEvento::class);
    }

    /**
     * Un cliente solo ve las solicitudes que el mismo creo; el
     * administrador de la PyME ve todas las de su tenant.
     * La RLS ya impide cruzar de tenant; esto limita dentro del tenant.
     */
    public function perteneceA(Usuario $usuario): bool
    {
        return $this->usuario_id === $usuario->id;
    }
}