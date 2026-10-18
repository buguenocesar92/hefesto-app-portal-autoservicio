<?php

declare(strict_types=1);

use App\Enums\EstadoSolicitud;
use App\Enums\PrioridadSolicitud;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de servicio: el modulo central del portal.
 *
 * `folio` es el numero visible para el cliente (por ejemplo "SOL-1042").
 * Es UNICO por tenant, no global: cada PyME numera desde 1. El indice
 * unico es (tenant_id, folio) precisamente porque el aislamiento es por
 * tenant; si el indice fuera solo por folio, dos Pymes no podrian tener
 * la misma numeracion, que es justo lo que se quiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->string('folio', 32);
            $tabla->foreignUuid('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $tabla->foreignUuid('categoria_id')->nullable()->constrained('categorias_servicio')->nullOnDelete();
            $tabla->string('titulo', 160);
            $tabla->text('descripcion');
            $tabla->string('estado', 20)->default(EstadoSolicitud::Recibida->value);
            $tabla->string('prioridad', 20)->default(PrioridadSolicitud::Normal->value);
            $tabla->jsonb('datos')->nullable();
            $tabla->foreignUuid('asignado_a')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('cerrada_en')->nullable();
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'folio']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'usuario_id']);
            $tabla->index(['tenant_id', 'creado_en']);
        });

        // Contador de folios por tenant. El folio se calcula dentro de la
        // misma transaccion que el INSERT, con el bloqueo de fila de
        // este contador, para que dos altas simultaneas no repitan folio.
        DB::statement(<<<'SQL'
            CREATE TABLE contadores_folio (
                tenant_id uuid PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                ultimo_numero integer NOT NULL DEFAULT 0,
                actualizado_en timestamptz NOT NULL DEFAULT now()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS contadores_folio');
        Schema::dropIfExists('solicitudes');
    }
};