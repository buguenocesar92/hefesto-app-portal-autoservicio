<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de sesiones de Laravel.
 *
 * La sesion va a la base de datos y no a archivos porque la API corre
 * en mas de un contenedor: con el controlador de archivos, el usuario
 * saltaria de sesion al ser atendido por otra replica. Ver docs/01,
 * seccion "Por que la sesion vive en PostgreSQL".
 *
 * La cookie que lleva el identificador es HttpOnly, Secure y SameSite=Lax
 * (configurable en config/session.php y .env).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->foreignUuid('user_id')->nullable()->index();
            $tabla->string('ip_address', 45)->nullable();
            $tabla->text('user_agent')->nullable();
            $tabla->text('payload');
            $tabla->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};