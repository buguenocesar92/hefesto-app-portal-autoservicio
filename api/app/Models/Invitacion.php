<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invitacion para que una persona use el portal.
 *
 * El alta real del usuario en Authentik es manual o por flujo de
 * Authentik: este registro solo autoriza el CORREO. Si el correo no
 * esta invitado y su dominio no pertenece a la PyME, el login se
 * rechaza. Es la red de seguridad del alta de usuarios.
 *
 * AISLAMIENTO: RLS por `tenant_id`.
 */
class Invitacion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'invitaciones';

    protected $fillable = [
        'tenant_id',
        'correo',
        'rol',
        'token_hash',
        'invitado_por',
        'expira_en',
        'aceptada_en',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolUsuario::class,
            'expira_en' => 'datetime',
            'aceptada_en' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function estaVigente(): bool
    {
        if ($this->aceptada_en !== null) {
            return false;
        }

        return $this->expira_en === null || $this->expira_en->isFuture();
    }

    /**
     * El token se guarda hasheado con SHA-256. Con SHA-256 no se puede
     * "verificar" un token robado de forma que sirva para algo mas,
     * pero el token nunca se persiste en claro, ni en la base ni en
     * un correo. La busqueda por token usa el hash.
     */
    public static function hashearToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function correoNormalizado(string $correo): string
    {
        return Str::lower(trim($correo));
    }
}