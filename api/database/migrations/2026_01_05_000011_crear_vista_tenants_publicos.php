<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista publica de tenants, usada en el paso previo al login.
 *
 * Cuando un usuario entra al portal todavia no hay sesion y por lo tanto
 * no hay `app.tenant_id` que poner. Esa es la unica ventana en que se
 * consulta el catalogo de Pymes. Para que esa ventana no sirva para
 * enumerar clientes, la vista expone SOLO tres columnas y solo de los
 * tenants activos:
 *
 *   slug    -> es publico: aparece en la URL de entrada del portal
 *   nombre  -> es el nombre comercial que el cliente ya conoce
 *   activo  -> siempre true aqui (el filtro esta en el WHERE)
 *
 * `razon_social`, `rut`, correos y telefonos NO se exponen.
 *
 * Compensacion: la tabla `tenants` sigue sin RLS porque debe poder
 * leerse sin contexto de tenant. Quien tenga acceso SQL a la base
 * puede leerla entera; por eso el rol de la aplicacion no es
 * superusuario y el despliegue exige revisar grants. Ver docs/03,
 * seccion "El punto ciego de `tenants`".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_tenants_publicos AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.tenants
            WHERE activo = true;

            COMMENT ON VIEW public.vw_tenants_publicos IS
            'Unica lectura de tenants permitida antes del login. No exponer otras columnas en respuestas de API.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_tenants_publicos');
    }
};