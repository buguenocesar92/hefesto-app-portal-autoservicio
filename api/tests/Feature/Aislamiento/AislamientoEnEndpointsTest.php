<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Solicitud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El aislamiento visto desde la API, que es donde de verdad importa.
 *
 * Aqui se comprueba lo que ve una persona CON SESION: que el hecho de estar
 * autenticado no baste para pasar de una PyME a otra.
 *
 * Se espera 404 y no 403 a proposito: un 403 confirmaria que el recurso
 * existe en otra PyME, lo que ya es filtrar informacion.
 */
class AislamientoEnEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1';

    public function test_un_cliente_no_ve_las_solicitudes_de_otra_pyme(): void
    {
        $primera = $this->crearPyme('primera.cl');
        $solicitudDeMiPyme = Solicitud::factory()->create([
            'tenant_id' => $primera['tenant']->id,
            'usuario_id' => $primera['cliente']->id,
        ]);

        $segunda = $this->crearSegundaPyme('segunda.cl');
        $solicitudAjena = Solicitud::factory()->create([
            'tenant_id' => $segunda['tenant']->id,
            'usuario_id' => $segunda['cliente']->id,
        ]);

        $this->comoUsuario($primera['cliente']);

        $listado = $this->getJson(self::RUTA . '/solicitudes')->assertOk();

        $ids = collect($listado->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($solicitudDeMiPyme->id));
        $this->assertFalse($ids->contains($solicitudAjena->id));

        $this->getJson(self::RUTA . '/solicitudes/' . $solicitudAjena->id)->assertNotFound();
    }

    public function test_un_cliente_no_puede_comentar_una_solicitud_de_otra_pyme(): void
    {
        $primera = $this->crearPyme('primera.cl');
        $segunda = $this->crearSegundaPyme('segunda.cl');

        $solicitudAjena = Solicitud::factory()->create([
            'tenant_id' => $segunda['tenant']->id,
            'usuario_id' => $segunda['cliente']->id,
        ]);

        $this->comoUsuario($primera['cliente']);

        $this->postJson(self::RUTA . '/solicitudes/' . $solicitudAjena->id . '/eventos', [
            'comentario' => 'Comentario que deberia quedar fuera.',
        ])->assertNotFound();

        $this->assertDatabaseCount('solicitud_eventos', 0);
    }

    public function test_el_administrador_de_una_pyme_no_administra_a_otra(): void
    {
        $primera = $this->crearPyme('primera.cl');
        $segunda = $this->crearSegundaPyme('segunda.cl');

        $usuarioAjeno = $segunda['cliente'];

        $this->comoUsuario($primera['administrador']);

        // Ni lo ve en el listado...
        $listado = $this->getJson(self::RUTA . '/admin/usuarios')->assertOk();
        $this->assertFalse(
            collect($listado->json('data'))->pluck('id')->contains($usuarioAjeno->id)
        );

        // ...ni lo puede editar...
        $this->patchJson(self::RUTA . '/admin/usuarios/' . $usuarioAjeno->id, [
            'rol' => 'administrador',
        ])->assertNotFound();

        // ...ni desactivarlo.
        $this->postJson(self::RUTA . '/admin/usuarios/' . $usuarioAjeno->id . '/desactivar')
            ->assertNotFound();

        $this->assertDatabaseHas('usuarios', [
            'id' => $usuarioAjeno->id,
            'rol' => 'cliente',
            'activo' => true,
        ]);
    }

    public function test_un_cliente_no_abre_una_solicitud_con_categoria_de_otra_pyme(): void
    {
        $primera = $this->crearPyme('primera.cl');
        $segunda = $this->crearSegundaPyme('segunda.cl');

        $categoriaAjena = \App\Models\CategoriaServicio::factory()->create([
            'tenant_id' => $segunda['tenant']->id,
        ]);

        $this->comoUsuario($primera['cliente']);

        // La validacion `exists` corre dentro del contexto del tenant, asi
        // que una categoria de otra PyME se comporta como inexistente.
        $this->postJson(self::RUTA . '/solicitudes', [
            'categoria_id' => $categoriaAjena->id,
            'titulo' => 'Solicitud con categoria ajena',
            'descripcion' => 'No deberia poder crearse.',
            'prioridad' => 'normal',
        ])->assertStatus(422)->assertJsonValidationErrors('categoria_id');

        $this->assertDatabaseCount('solicitudes', 0);
    }

    public function test_sin_sesion_no_se_entra_a_nada(): void
    {
        $this->getJson(self::RUTA . '/solicitudes')->assertUnauthorized();
        $this->getJson(self::RUTA . '/admin/usuarios')->assertUnauthorized();
        $this->getJson(self::RUTA . '/documentos')->assertUnauthorized();
    }
}