<?php

declare(strict_types=1);

namespace Tests\Feature\Solicitudes;

use App\Enums\EstadoSolicitud;
use App\Models\CategoriaServicio;
use App\Models\Solicitud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Solicitudes de servicio: el modulo central.
 */
class SolicitudesTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1';

    public function test_un_cliente_abre_una_solicitud_y_recibe_el_folio(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $this->comoUsuario($pyme['cliente']);

        $respuesta = $this->postJson(self::RUTA . '/solicitudes', [
            'titulo' => 'El refrigerador suena fuerte',
            'descripcion' => 'Hace un ruido fuerte cada vez que parte el compresor.',
            'prioridad' => 'alta',
        ])->assertCreated();

        $this->assertSame('SOL-00001', $respuesta->json('data.folio'));
        $this->assertSame('recibida', $respuesta->json('data.estado'));

        $this->assertDatabaseHas('solicitudes', [
            'folio' => 'SOL-00001',
            'usuario_id' => $pyme['cliente']->id,
            'tenant_id' => $pyme['tenant']->id,
        ]);

        // La apertura queda en la bitacora de la solicitud.
        $this->assertDatabaseCount('solicitud_eventos', 1);
    }

    public function test_el_folio_es_una_numeracion_propia_de_cada_pyme(): void
    {
        $primera = $this->crearPyme('primera.cl');
        $this->comoUsuario($primera['cliente']);

        $this->postJson(self::RUTA . '/solicitudes', [
            'titulo' => 'Primera solicitud',
            'descripcion' => 'Contenido suficiente para la validacion.',
            'prioridad' => 'normal',
        ])->assertCreated()->assertJsonPath('data.folio', 'SOL-00001');

        $segunda = $this->crearSegundaPyme('segunda.cl');
        $this->comoUsuario($segunda['cliente']);

        // La segunda PyME tambien empieza en 1: el folio es unico por
        // tenant, no global.
        $this->postJson(self::RUTA . '/solicitudes', [
            'titulo' => 'Primera solicitud',
            'descripcion' => 'Contenido suficiente para la validacion.',
            'prioridad' => 'normal',
        ])->assertCreated()->assertJsonPath('data.folio', 'SOL-00001');
    }

    public function test_se_validan_los_datos_que_pide_la_categoria(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $categoria = CategoriaServicio::factory()->conCampos()->create([
            'tenant_id' => $pyme['tenant']->id,
        ]);

        $this->comoUsuario($pyme['cliente']);

        // Falta el numero de serie, que es obligatorio en la categoria.
        $this->postJson(self::RUTA . '/solicitudes', [
            'categoria_id' => $categoria->id,
            'titulo' => 'Falta el numero de serie',
            'descripcion' => 'Contenido suficiente para la validacion.',
            'prioridad' => 'normal',
            'datos' => ['franja_horaria' => 'manana'],
        ])->assertStatus(422)->assertJsonValidationErrors('datos.numero_serie');

        // Con la opcion fuera de la lista, tambien falla.
        $this->postJson(self::RUTA . '/solicitudes', [
            'categoria_id' => $categoria->id,
            'titulo' => 'Franja invalida',
            'descripcion' => 'Contenido suficiente para la validacion.',
            'prioridad' => 'normal',
            'datos' => ['numero_serie' => 'RF-123', 'franja_horaria' => 'medianoche'],
        ])->assertStatus(422)->assertJsonValidationErrors('datos.franja_horaria');

        // Con los dos correctos, se acepta.
        $this->postJson(self::RUTA . '/solicitudes', [
            'categoria_id' => $categoria->id,
            'titulo' => 'Solicitud completa',
            'descripcion' => 'Contenido suficiente para la validacion.',
            'prioridad' => 'normal',
            'datos' => ['numero_serie' => 'RF-123', 'franja_horaria' => 'tarde'],
        ])->assertCreated();
    }

    public function test_un_cliente_no_ve_las_solicitudes_de_otro_cliente_de_su_pyme(): void
    {
        $pyme = $this->crearPyme('primera.cl');

        Solicitud::factory()->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['cliente']->id,
        ]);

        Solicitud::factory()->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['administrador']->id,
        ]);

        $this->comoUsuario($pyme['cliente']);

        $listado = $this->getJson(self::RUTA . '/solicitudes')->assertOk();

        $this->assertCount(1, $listado->json('data'));
    }

    public function test_el_cliente_no_puede_cambiar_el_estado(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $solicitud = Solicitud::factory()->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['cliente']->id,
            'estado' => EstadoSolicitud::Recibida,
        ]);

        $this->comoUsuario($pyme['cliente']);

        $this->patchJson(self::RUTA . '/solicitudes/' . $solicitud->id . '/estado', [
            'estado' => 'cerrada',
        ])->assertNotFound();

        $this->assertSame('recibida', $solicitud->refresh()->estado->value);
    }

    public function test_el_administrador_mueve_el_estado_y_deja_rastro(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $solicitud = Solicitud::factory()->en(EstadoSolicitud::Recibida)->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['cliente']->id,
        ]);

        $this->comoUsuario($pyme['administrador']);

        $this->patchJson(self::RUTA . '/solicitudes/' . $solicitud->id . '/estado', [
            'estado' => 'en_revision',
            'comentario' => 'Estamos revisando.',
        ])->assertOk()->assertJsonPath('data.estado', 'en_revision');

        $this->assertDatabaseHas('solicitud_eventos', [
            'solicitud_id' => $solicitud->id,
            'estado_anterior' => 'recibida',
            'estado_nuevo' => 'en_revision',
            'comentario' => 'Estamos revisando.',
        ]);
    }

    public function test_no_se_puede_hacer_un_salto_de_estado_invalido(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $solicitud = Solicitud::factory()->en(EstadoSolicitud::Cerrada)->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['cliente']->id,
        ]);

        $this->comoUsuario($pyme['administrador']);

        // De "cerrada" no se sale: la maquina de estados no lo permite.
        $this->patchJson(self::RUTA . '/solicitudes/' . $solicitud->id . '/estado', [
            'estado' => 'en_revision',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_al_cerrar_se_guarda_la_fecha_de_cierre(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $solicitud = Solicitud::factory()->en(EstadoSolicitud::Resuelta)->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['cliente']->id,
        ]);

        $this->assertNull($solicitud->cerrada_en);

        $this->comoUsuario($pyme['administrador']);

        $this->patchJson(self::RUTA . '/solicitudes/' . $solicitud->id . '/estado', [
            'estado' => 'cerrada',
        ])->assertOk();

        $this->assertNotNull($solicitud->refresh()->cerrada_en);
    }

    public function test_el_cliente_comenta_su_solicitud_sin_mover_el_estado(): void
    {
        $pyme = $this->crearPyme('primera.cl');
        $solicitud = Solicitud::factory()->en(EstadoSolicitud::Agendada)->create([
            'tenant_id' => $pyme['tenant']->id,
            'usuario_id' => $pyme['cliente']->id,
        ]);

        $this->comoUsuario($pyme['cliente']);

        $respuesta = $this->postJson(
            self::RUTA . '/solicitudes/' . $solicitud->id . '/eventos',
            ['comentario' => 'Cualquiera, estoy en la casa.']
        )->assertCreated();

        $this->assertSame('agendada', $respuesta->json('data.estado_nuevo'));
        $this->assertFalse($respuesta->json('data.es_cambio_de_estado'));

        $this->assertSame('agendada', $solicitud->refresh()->estado->value);
    }
}