<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as UsuarioAutenticable;
use Illuminate\Support\Str;

/**
 * Usuario final de una PyME (el cliente del cliente de Hefesto).
 *
 * La identidad vive en Authentik: aqui solo se guarda el vinculo
 * (`authentik_sub`), el correo verificado por el IdP y el rol local.
 *
 * Hereda de `Illuminate\Foundation\Auth\User` (y no de Model) porque es lo
 * que hace que el guard de sesion lo acepte: esa clase implementa el
 * contrato `Authenticatable`, sin el cual `Auth::login()` falla. No tiene
 * contrasena propia: la de la persona vive en Authentik.
 *
 * AISLAMIENTO: la tabla tiene RLS por `tenant_id`. Una consulta sin
 * contexto de tenant devuelve cero filas, nunca filas de otro cliente.
 */
class Usuario extends UsuarioAutenticable
{
    use HasFactory;
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'authentik_sub',
        'correo',
        'nombre',
        'apellido',
        'telefono',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'authentik_sub',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolUsuario::class,
            'activo' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function esAdministrador(): bool
    {
        return $this->rol === RolUsuario::Administrador;
    }

    /**
     * Determina si un correo corresponde a este usuario.
     * El correo se guarda siempre en minusculas.
     */
    public function correoCoincide(string $correo): bool
    {
        return Str::lower(trim($correo)) === $this->correo;
    }

    /** Nombre para mostrar, sin inventar datos que el IdP no entrego. */
    public function nombreCompleto(): string
    {
        $partes = array_filter([$this->nombre, $this->apellido]);

        return $partes === []
            ? $this->correo
            : implode(' ', $partes);
    }
}