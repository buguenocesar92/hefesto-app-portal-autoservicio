<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Categoria de servicio ofrecida por la PyME (por ejemplo "Mantencion
 * preventiva", "Reparacion de equipos", "Instalacion en sitio").
 *
 * Define los `campos` dinamicos que el cliente debe completar al crear
 * una solicitud. AISLAMIENTO: RLS por `tenant_id`.
 */
class CategoriaServicio extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'categorias_servicio';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'descripcion',
        'campos',
        'tiempo_respuesta_sugerido',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'campos' => 'array',
            'activa' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function solicitudes()
    {
        return $this->hasMany(Solicitud::class, 'categoria_id');
    }
}