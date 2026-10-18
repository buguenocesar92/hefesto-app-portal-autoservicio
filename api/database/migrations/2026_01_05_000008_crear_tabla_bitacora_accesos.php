<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de acceso (append-only).
 *
 * Se escribe desde App\Services\Tenancy\AuditorDeAcceso, que la invoca
 * el middleware AuditarAcceso al terminar cada peticion.
 *
 * No tiene `updated_at` a proposito: una linea de bitacora no se edita.
 * La retencion la aplica el operador (ver docs/07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_accesos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            // `tenant_id` es NOT NULL a proposito: toda linea de bitacora
            // pertenece a un tenant concreto. Los hechos que ocurren antes
            // del login (por ejemplo, un intento con estado invalido) no
            // tienen tenant, y por eso NO se registran aqui: van al canal
            // de log `portal.seguridad` (ver docs/08).
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->string('accion', 60);
            $tabla->string('entidad', 40)->nullable();
            $tabla->uuid('entidad_id')->nullable();
            $tabla->string('metodo_http', 8)->nullable();
            $tabla->string('ruta', 255)->nullable();
            $tabla->string('direccion_ip', 45)->nullable();
            $tabla->string('agente_usuario', 255)->nullable();
            $tabla->boolean('exito')->default(true);
            $tabla->timestampTz('registrado_en');

            $tabla->index(['tenant_id', 'registrado_en']);
            $tabla->index(['usuario_id', 'registrado_en']);
            $tabla->index(['accion', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_accesos');
    }
};