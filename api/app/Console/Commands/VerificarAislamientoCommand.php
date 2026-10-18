<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comprueba que el aislamiento entre Pymes esta realmente puesto.
 *
 * POR QUE ESTE COMANDO EXISTE
 * ---------------------------
 * El aislamiento no es solo el codigo que escribimos: es el estado en que
 * quedo la base de datos. Un despliegue bien intentionado pero mal configurado
 * (un rol con BYPASSRLS, una politica borrada a mano, una tabla nueva sin
 * proteger) deja la aplicacion funcionando y, al mismo tiempo, completamente
 * abierta entre clientes. Nada en la UI lo delata.
 *
 * Por eso este comando se ejecuta en el despliegue y antes de dar por buena
 * una migracion. Devuelve codigo de salida 1 si algo falla.
 *
 *   php artisan portal:verificar-aislamiento
 */
class VerificarAislamientoCommand extends Command
{
    /** @var list<string> */
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

    protected $signature = 'portal:verificar-aislamiento';

    protected $description = 'Comprueba que la RLS, el rol de base de datos y el contexto de tenant protegen los datos de cada PyME.';

    public function handle(): int
    {
        $fallos = [];

        $fallos = array_merge($fallos, $this->revisarRol());
        $fallos = array_merge($fallos, $this->revisarTablas());
        $fallos = array_merge($fallos, $this->revisarFuncionDeContexto());
        $fallos = array_merge($fallos, $this->revisarLecturaAislada());
        $fallos = array_merge($fallos, $this->revisarVistaPublica());

        if ($fallos !== []) {
            $this->error('El aislamiento entre Pymes NO esta bien puesto:');

            foreach ($fallos as $fallo) {
                $this->line('  - ' . $fallo);
            }

            $this->line('');
            $this->line('No sigas con el despliegue. Revisa la migracion');
            $this->line('database/migrations/2026_01_05_000009_activar_row_level_security.php');

            return self::FAILURE;
        }

        $this->info('Aislamiento entre Pymes verificado correctamente.');

        return self::SUCCESS;
    }

    /**
     * El rol de la aplicacion no puede ser superusuario ni tener BYPASSRLS.
     *
     * Con cualquiera de las dos cosas, PostgreSQL ignora las politicas RLS
     * por completo: las consultas devolverian filas de todas las Pymes y la
     * aplicacion no tendria como enterarse.
     *
     * @return list<string>
     */
    private function revisarRol(): array
    {
        $fila = DB::selectOne(
            'SELECT r.rolname AS nombre, r.rolsuper AS superusuario, r.rolbypassrls AS salta_rls
             FROM pg_roles r
             WHERE r.rolname = current_user'
        );

        if ($fila === null) {
            return ['No se pudo identificar el rol de la aplicacion en PostgreSQL.'];
        }

        $fallos = [];

        if ((bool) $fila->superusuario) {
            $fallos[] = sprintf(
                'El rol "%s" es SUPERUSUIRDO: ignora todas las politicas RLS.',
                $fila->nombre
            );
        }

        if ((bool) $fila->salta_rls) {
            $fallos[] = sprintf(
                'El rol "%s" tiene BYPASSRLS: ignora todas las politicas RLS.',
                $fila->nombre
            );
        }

        if ($fallos === []) {
            $this->line('  [ok] Rol "' . $fila->nombre . '": sin superusuario y sin BYPASSRLS.');
        }

        return $fallos;
    }

    /**
     * Cada tabla de negocio tiene RLS activada, forzada y con politica.
     *
     * @return list<string>
     */
    private function revisarTablas(): array
    {
        $fallos = [];

        foreach ($this->tablasProtegidas as $tabla) {
            $estado = DB::selectOne(
                "SELECT c.relrowsecurity AS rls,
                        c.relforcerowsecurity AS forzada
                 FROM pg_class c
                 WHERE c.oid = to_regclass(?)",
                ['public.' . $tabla]
            );

            if ($estado === null) {
                $fallos[] = 'La tabla public.' . $tabla . ' no existe. Falto correr las migraciones.';

                continue;
            }

            if (! $estado->rls) {
                $fallos[] = 'La tabla public.' . $tabla . ' NO tiene Row Level Security activada.';

                continue;
            }

            if (! $estado->forzada) {
                $fallos[] = 'La tabla public.' . $tabla . ' tiene RLS pero NO esta forzada '
                    . '(FORCE ROW LEVEL SECURITY): el dueno de la tabla saltaria la politica.';

                continue;
            }

            $politicas = DB::selectOne(
                'SELECT count(*) AS total
                 FROM pg_policies
                 WHERE schemaname = ? AND tablename = ?',
                ['public', $tabla]
            );

            if ((int) ($politicas->total ?? 0) === 0) {
                $fallos[] = 'La tabla public.' . $tabla . ' tiene RLS activada pero no tiene ninguna politica.';
            }
        }

        if ($fallos === []) {
            $this->line('  [ok] ' . count($this->tablasProtegidas) . ' tablas con RLS activada, forzada y con politica.');
        }

        return $fallos;
    }

    /**
     * Sin contexto de tenant, la funcion de contexto tiene que FALLAR.
     *
     * Si devolviera NULL o una cadena vacia en vez de lanzar excepcion, una
     * consulta sin tenant devolveria cero filas por accidente en vez de por
     * diseno, y en el futuro alguien podria "arreglarlo" devolviendo NULL y
     * abrir la puerta sin darse cuenta.
     *
     * @return list<string>
     */
    private function revisarFuncionDeContexto(): array
    {
        try {
            DB::select('SELECT public.app_tenant_id()');
        } catch (QueryException) {
            $this->line('  [ok] public.app_tenant_id() falla sin contexto de tenant (aislamiento cerrado).');

            return [];
        }

        return [
            'public.app_tenant_id() devolvio un valor sin que haya contexto de tenant. '
            . 'Deberia lanzar excepcion para que el aislamiento falle cerrado.',
        ];
    }

    /**
     * Con un tenant que no existe, las tablas de negocio deben salir vacias.
     *
     * @return list<string>
     */
    private function revisarLecturaAislada(): array
    {
        $tenantInexistente = (string) Str::uuid();
        $contexto = new ContextoTenant();
        $fallos = [];

        try {
            $conteo = $contexto->ejecutarAislado(
                $tenantInexistente,
                fn (): int => DB::table('solicitudes')->count()
            );
        } catch (QueryException $excepcion) {
            return ['No se pudo leer "solicitudes" con un contexto de tenant: ' . $excepcion->getMessage()];
        }

        if ($conteo !== 0) {
            $fallos[] = 'La tabla "solicitudes" devolvio ' . $conteo . ' filas para un tenant inexistente.';
        }

        $contextoResiduo = $contexto->tenantIdEnConexion();

        if ($contextoResiduo !== null) {
            $fallos[] = 'La variable app.tenant_id quedo fijada (' . $contextoResiduo . ') despues de la transaccion. '
                . 'Si queda colgando, el worker de colas podria leer datos de otra PyME.';
        }

        if ($fallos === []) {
            $this->line('  [ok] Con un tenant inexistente no se lee ninguna fila y el contexto no queda colgando.');
        }

        return $fallos;
    }

    /**
     * La vista publica de tenants debe existir y exponer solo tres columnas.
     *
     * @return list<string>
     */
    private function revisarVistaPublica(): array
    {
        $columnas = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_tenants_publicos'
             ORDER BY column_name"
        );

        if ($columnas === []) {
            return ['No existe la vista public.vw_tenants_publicos.'];
        }

        $nombres = array_map(static fn ($fila): string => (string) $fila->column_name, $columnas);
        sort($nombres);

        $esperadas = ['activo', 'nombre', 'slug'];
        sort($esperadas);

        if ($nombres !== $esperadas) {
            return ['La vista public.vw_tenants_publicos expone columnas distintas de las tres esperadas ('
                . implode(', ', $nombres) . '). No debe exponer datos de contacto de los clientes.'];
        }

        $this->line('  [ok] La vista publica de tenants expone solo slug, nombre y activo.');

        return [];
    }
}