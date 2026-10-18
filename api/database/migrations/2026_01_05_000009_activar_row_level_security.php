<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AISLAMIENTO ENTRE PYMES: Row Level Security (RLS) de PostgreSQL.
 *
 * Esta migracion es el corazon del producto. Ver docs/03 para el
 * analisis completo de por que se eligio RLS y no esquema por cliente.
 *
 * MECANISMO
 * ----------
 * 1. `public.app_tenant_id()` lee el valor de la variable de sesion
 *    `app.tenant_id`. Si no esta definida, la funcion LANZA un error.
 *    No devuelve NULL ni una cadena vacia: falla de forma ruidosa.
 * 2. Cada tabla sensible tiene una politica que exige
 *    `tenant_id = app_tenant_id()`, tanto al leer (USING) como al
 *    escribir (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la politica se aplique
 *    tambien al dueno de la tabla. Sin FORCE, el dueno la saltaria.
 * 4. La variable se fija con `set_config('app.tenant_id', ..., true)`,
 *    es decir, con alcance de TRANSACCION. Al confirmar o revertir la
 *    transaccion, PostgreSQL la descarta sola. Por eso no puede quedar
 *    colgada en una conexion reutilizada por el worker de colas, que es
 *    donde un `SET` normal filtraria datos de una PyME a otra.
 *
 * LA TABLA `tenants` NO TIENE RLS, a proposito: se consulta durante el
 * login, cuando todavia no hay tenant de sesion. Se compensa con la
 * vista `vw_tenants_publicos`, que expone solo tres columnas.
 *
 * REQUISITO DE DESPLIEGUE: el rol que usa la aplicacion NO puede ser
 * superusuario ni tener BYPASSRLS. Con superusuario, PostgreSQL ignora
 * las politicas por completo. El comando
 * `php artisan portal:verificar-aislamiento` lo comprueba.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * @var list<string>
     */
    private array $tablasProtegidas = [
        'usuarios',
        'invitaciones',
        'categorias_servicio',
        'solicitudes',
        'solicitud_eventos',
        'documentos',
        'bitacora_accesos',
        'contadores_folio',
    ];

    public function up(): void
    {
        // 1. Funcion de contexto. SECURITY INVOKER (lo normal): no
        //    necesita privilegios propios.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de tenant en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otra PyME.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverTenant, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
            'Devuelve el tenant de la peticion. Lanza excepción si no hay contexto: el aislamiento falla cerrado.';
        SQL);

        // 2. Politicas y activacion por tabla.
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT to_regclass(?) AS tabla',
                ['public.' . $tabla]
            );

            // to_regclass devuelve NULL si la tabla todavia no existe.
            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');

            // FORCE hace que la politica aplique tambien al dueno.
            // Es indispensable: en desarrollo el dueno suele ser el mismo
            // rol que corre la aplicacion.
            DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

            $nombre = 'politica_aislamiento_' . $tabla;

            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

                CREATE POLICY {$nombre} ON public.{$tabla}
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL);
        }

        // 3. Las tablas de solo lectura de la sesion (jobs fallidos,
        //    cache de Laravel) no llevan tenant_id y por eso no se
        //    incluyen arriba. Si alguien las agrega a la lista sin
        //    columna tenant_id, la migracion falla de forma explicita
        //    en lugar de dejar una tabla sin proteccion.
        $this->verificarColumnaTenant();
    }

    public function down(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_' . $tabla . ' ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene
     * `tenant_id`. Sin esto, un error de tipeo dejaria una tabla de
     * datos de negocio sin politica y la migracion passaria igual.
     */
    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ''public'' AND table_name = ? AND column_name = ''tenant_id''
                 ) AS tiene_columna',
                [$tabla]
            );

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                    . 'Revise la migracion antes de continuar.'
                );
            }
        }
    }
};