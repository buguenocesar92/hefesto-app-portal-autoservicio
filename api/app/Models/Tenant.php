<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * La PyME cliente de Hefesto. Es la raiz del aislamiento.
 *
 * Esta tabla NO tiene RLS a proposito: es el catalogo que se consulta
 * durante el login, cuando todavia no existe un tenant de sesion.
 * Unico dato que se expone antes de autenticar: la vista
 * `vw_tenants_publicos` (slug, nombre, activo). Ver docs/03.
 */
class Tenant extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'rut',
        'dominio_correo',
        'correo_contacto',
        'telefono_contacto',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Slug con el que se identifica al cliente en la URL de entrada.
     * Se normaliza en minusculas sin acentos.
     */
    public static function normalizarSlug(string $valor): string
    {
        $transliterado = Str::ascii($valor);

        return Str::of($transliterado)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->limit(48, '')
            ->value();
    }

    /** Dominio de correo en minusculas, sin la arroba. */
    public static function normalizarDominio(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return Str::lower(trim($valor));
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(Solicitud::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class);
    }

    public function categoriasServicio(): HasMany
    {
        return $this->hasMany(CategoriaServicio::class);
    }
}