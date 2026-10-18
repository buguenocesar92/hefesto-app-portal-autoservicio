<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de estados de cada solicitud (append-only).
 *
 * Solo tiene `registrado_en`: no hay `updated_at` porque un evento ya
 * escrito no se modifica. La API no expone forma de editar ni borrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_eventos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('solicitud_id')->constrained('solicitudes')->cascadeOnDelete();
            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->string('estado_anterior', 20)->nullable();
            $tabla->string('estado_nuevo', 20);
            $tabla->text('comentario')->nullable();
            $tabla->timestampTz('registrado_en');

            $tabla->index(['solicitud_id', 'registrado_en']);
            $tabla->index(['tenant_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_eventos');
    }
};