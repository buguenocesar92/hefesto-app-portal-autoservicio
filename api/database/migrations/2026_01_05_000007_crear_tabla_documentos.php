<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos emitidos por la PyME y disponibles para su cliente.
 *
 * `ruta_archivo` es RELATIVA al volumen de documentos. El nombre
 * original del archivo se guarda aparte y es lo que se descarga.
 *
 * `hash_sha256` permite detectar alteracion del archivo en disco y
 * validar un respaldo restaurado. No es una firma criptografica: solo
 * detecta corrupcion y cambios accidentales.
 *
 * El portal NO emite documentos: solo publica los que la PyME ya
 * emitio. Ver docs/09.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->string('tipo', 24);
            $tabla->string('numero', 40);
            $tabla->string('detalle', 200)->nullable();
            $tabla->date('emitido_en');
            $tabla->decimal('monto_bruto', 14, 2)->nullable();
            $tabla->string('moneda', 3)->default('CLP');
            $tabla->string('ruta_archivo', 255);
            $tabla->string('nombre_archivo', 190);
            $tabla->string('mime', 120)->nullable();
            $tabla->unsignedBigInteger('tamano_bytes')->nullable();
            $tabla->char('hash_sha256', 64)->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'emitido_en']);
            $tabla->index(['tenant_id', 'tipo']);
            $tabla->index(['tenant_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};