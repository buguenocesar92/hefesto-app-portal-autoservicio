<?php

declare(strict_types=1);

use App\Enums\RolUsuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios finales de cada PyME.
 *
 * `authentik_sub` es el identificador de la persona en Authentik. Es
 * UNICO en toda la instalacion: una misma persona no puede estar
 * inscrita en dos Pymes con el mismo `sub`, porque Authentik tiene una
 * sola identidad por persona. Por eso `unicidad` es global y no
 * (tenant_id, authentik_sub).
 *
 * `correo` NO es unico: dos Pymes distintas pueden tener clientes con
 * el mismo correo (por ejemplo, un ilustrador que trabaja para varias).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->string('authentik_sub', 190)->unique();
            $tabla->string('correo', 190);
            $tabla->string('nombre', 120)->nullable();
            $tabla->string('apellido', 120)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('rol', 20)->default(RolUsuario::Cliente->value);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_acceso')->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'correo']);
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};