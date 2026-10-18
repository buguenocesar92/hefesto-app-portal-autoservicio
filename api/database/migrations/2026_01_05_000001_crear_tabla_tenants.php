<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de Pymes clientes de Hefesto.
 *
 * Esta tabla es la EXCEPCION consciente al aislamiento: no lleva RLS
 * porque se consulta durante el login, cuando todavia no hay tenant de
 * sesion. Por eso la aplicacion nunca la expone cruda: el unico acceso
 * de lectura es la vista `vw_tenants_publicos` (ver migracion
 * 2026_01_05_000010).
 *
 * Claves foraneas del resto del esquema apuntan aqui, de modo que un
 * registro de un tenant jamas puede quedar huerfano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 48)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 160)->nullable();
            $tabla->string('rut', 16)->nullable();
            $tabla->string('dominio_correo', 160)->nullable()->unique();
            $tabla->string('correo_contacto', 190)->nullable();
            $tabla->string('telefono_contacto', 32)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};