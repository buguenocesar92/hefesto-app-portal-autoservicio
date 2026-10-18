<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Solicitud;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: una PyME no puede leer los datos de otra.
 *
 * Estas pruebas van contra PostgreSQL de verdad, porque el aislamiento esta
 * implementado con Row Level Security. Si se corren contra SQLite, pasan sin
 * comprobar nada. Ver phpunit.xml.
 *
 * Lo que se comprueba aca:
 *
 *  1. Sin contexto de tenant, las tablas de negocio devuelven CERO filas y
 *     no fallan en silencio.
 *  2. Con el contexto de otra PyME, una consulta devuelve SOLO sus filas.
 *  3. Un `id` de otra PyME no existe para este usuario (404, no 403).
 *  4. El contexto de tenant no sobrevive a la transaccion.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_contexto_de_tenant_la_consulta_falla_en_vez_de_traer_datos(): void
    {
        $primera = $this->crearPyme('primera.cl');
        Solicitud::factory()->create(['tenant_id' => $primera['tenant']->id]);

        // Se limpia el contexto a proposito: es la situacion de una consulta
        // que se escapara del middleware.
        //
        // Lo que se espera NO es "cero filas", sino un error. `app_tenant_id()`
        // lanza excepcion cuando no hay tenant, y la politica RLS aborta la
        // consulta. Devolver cero filas seria mas comodo de probar, pero
        // dejaria pasar el caso peligroso: que alguien "arregle" la funcion
        // devolviendo NULL y termine leyendo filas de la PyME equivocada sin
        // que nada lo delate. Ahi el aislamiento falla cerrado.
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Solicitud::query()->count();
    }

    public function test_cada_pyme_solo_ve_sus_propias_filas(): void
    {
        $primera = $this->crearPyme('primera.cl');
        Solicitud::factory()->count(3)->create(['tenant_id' => $primera['tenant']->id]);

        $segunda = $this->crearSegundaPyme('segunda.cl');
        Solicitud::factory()->count(2)->create(['tenant_id' => $segunda['tenant']->id]);

        $this->fijarTenant($primera['tenant']->id);
        $this->assertSame(3, Solicitud::query()->count());
        $this->assertSame(
            $primera['tenant']->id,
            Solicitud::query()->value('tenant_id')
        );

        $this->fijarTenant($segunda['tenant']->id);
        $this->assertSame(2, Solicitud::query()->count());
        $this->assertSame(
            $segunda['tenant']->id,
            Solicitud::query()->value('tenant_id')
        );
    }

    public function test_la_solicitud_de_otra_pyme_no_se_puede_leer_ni_escribir(): void
    {
        $primera = $this->crearPyme('primera.cl');
        Solicitud::factory()->create(['tenant_id' => $primera['tenant']->id]);

        $segunda = $this->crearSegundaPyme('segunda.cl');
        $solicitudAjena = Solicitud::factory()->create([
            'tenant_id' => $segunda['tenant']->id,
        ]);

        // Volvemos al contexto de la primera PyME.
        $this->fijarTenant($primera['tenant']->id);

        $this->assertNull(Solicitud::find($solicitudAjena->id));

        // Y tampoco se puede tocar: la politica tiene WITH CHECK.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('solicitudes')
            ->where('id', $solicitudAjena->id)
            ->update(['titulo' => 'secuestrada']);
    }

    public function test_no_se_puede_insertar_una_fila_con_el_tenant_de_otra_pyme(): void
    {
        $primera = $this->crearPyme('primera.cl');
        $segunda = $this->crearSegundaPyme('segunda.cl');

        // Contexto de la primera PyME, pero se intenta guardar una solicitud
        // de la segunda. La politica WITH CHECK lo tiene que impedir.
        $this->fijarTenant($primera['tenant']->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Solicitud::query()->create([
            'tenant_id' => $segunda['tenant']->id,
            'folio' => 'SOL-99999',
            'usuario_id' => $primera['cliente']->id,
            'titulo' => 'Solicitud con tenant ajeno',
            'descripcion' => 'No deberia poder guardarse nunca.',
            'estado' => 'recibida',
            'prioridad' => 'normal',
        ]);
    }

    public function test_el_contexto_de_tenant_no_sobrevive_a_la_transaccion(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        Solicitud::factory()->create(['tenant_id' => $pyme['tenant']->id]);

        $contexto = new ContextoTenant();

        $conteo = $contexto->ejecutarAislado(
            $pyme['tenant']->id,
            fn (): int => Solicitud::query()->count()
        );

        $this->assertSame(1, $conteo);
        $this->assertNull(
            $contexto->tenantIdEnConexion(),
            'La variable de contexto debe desaparecer al terminar la transaccion. '
            . 'Si queda, el worker de colas podria leer datos de otra PyME.'
        );
    }

    public function test_la_funcion_de_contexto_falla_cerrada_sin_tenant(): void
    {
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::select('SELECT public.app_tenant_id()');
    }
}