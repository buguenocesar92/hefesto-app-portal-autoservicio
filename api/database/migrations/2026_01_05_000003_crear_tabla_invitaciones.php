<?php

declare(strict_types=1);

use App\Enums\RolUsuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitaciones de acceso al portal.
 *
 * El token se guarda como SHA-256 (`token_hash`). Nunca se persiste el
 * token en claro, para que un vuelco de la base de datos no entregue
 * invitaciones usables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->string('correo', 190);
            $tabla->string('rol', 20)->default(RolUsuario::Cliente->value);
            $tabla->string('token_hash', 64);
            $tabla->foreignUuid('invitado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('expira_en')->nullable();
            $tabla->timestampTz('aceptada_en')->nullable();
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'correo', 'token_hash']);
            $tabla->index(['tenant_id', 'correo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones');
    }
};