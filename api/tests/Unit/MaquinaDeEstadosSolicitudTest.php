<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoSolicitud;
use App\Services\Solicitudes\MaquinaDeEstadosSolicitud;
use PHPUnit\Framework\TestCase;

/**
 * Reglas del flujo de una solicitud.
 *
 * Es una prueba unitaria a proposito: la maquina de estados no toca la base de
 * datos, asi que se puede correr en cualquier lado y verificar que el flujo
 * no se rompa al cambiarla.
 */
class MaquinaDeEstadosSolicitudTest extends TestCase
{
    private MaquinaDeEstadosSolicitud $maquina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maquina = new MaquinaDeEstadosSolicitud();
    }

    public function test_una_solicitud_recibida_puede_pasar_a_revision_agendada_o_anulada(): void
    {
        $this->assertTrue($this->maquina->puedeTransicionar(
            EstadoSolicitud::Recibida,
            EstadoSolicitud::EnRevision
        ));

        $this->assertTrue($this->maquina->puedeTransicionar(
            EstadoSolicitud::Recibida,
            EstadoSolicitud::Agendada
        ));

        $this->assertFalse($this->maquina->puedeTransicionar(
            EstadoSolicitud::Recibida,
            EstadoSolicitud::Cerrada
        ));
    }

    public function test_una_solicitud_cerrada_no_sale_de_ahi(): void
    {
        foreach (EstadoSolicitud::cases() as $estado) {
            $this->assertFalse(
                $this->maquina->puedeTransicionar(EstadoSolicitud::Cerrada, $estado),
                'Una solicitud cerrada deberia ser un callejon sin salida, pero se permitio pasar a '
                    . $estado->value
            );
        }
    }

    public function test_una_solicitud_esperando_al_cliente_puede_reevaluarse(): void
    {
        $this->assertTrue($this->maquina->puedeTransicionar(
            EstadoSolicitud::EsperaCliente,
            EstadoSolicitud::EnRevision
        ));

        $this->assertTrue($this->maquina->puedeTransicionar(
            EstadoSolicitud::EsperaCliente,
            EstadoSolicitud::EnEjecucion
        ));
    }

    public function test_los_estados_terminales_no_estan_activos(): void
    {
        $activos = $this->maquina->estadosActivos();

        $this->assertNotContains(EstadoSolicitud::Cerrada, $activos);
        $this->assertNotContains(EstadoSolicitud::Anulada, $activos);
        $this->assertContains(EstadoSolicitud::Recibida, $activos);
    }

    public function test_cada_estado_tiene_una_etiqueta_para_la_persona(): void
    {
        $catalogo = $this->maquina->catalogo();

        $this->assertCount(count(EstadoSolicitud::cases()), $catalogo);

        foreach ($catalogo as $etiqueta) {
            $this->assertNotSame('', $etiqueta);
        }
    }
}