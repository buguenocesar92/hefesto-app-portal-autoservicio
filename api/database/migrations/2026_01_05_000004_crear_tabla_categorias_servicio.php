<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias de servicio definidas por cada PyME.
 *
 * `campos` es un jsonb con la definicion de los datos que el cliente
 * debe entregar (por ejemplo: numero de serie, direccion, franja
 * horaria). Formato:
 *
 *   [
 *     { "clave": "numero_serie", "etiqueta": "Numero de serie",
 *       "tipo": "texto", "requerido": true }
 *   ]
 *
 * `tipo` admitidos: texto, numero, fecha, seleccion, booleano.
 * La API valida la respuesta contra esta definicion antes de aceptarla
 * (ver SolicitudController::validarCamposDinamicos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_servicio', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->string('nombre', 120);
            $tabla->text('descripcion')->nullable();
            $tabla->jsonb('campos')->nullable();
            $tabla->unsignedInteger('tiempo_respuesta_sugerido')->nullable();
            $tabla->boolean('activa')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_servicio');
    }
};