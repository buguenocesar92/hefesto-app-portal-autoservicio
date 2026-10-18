<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas del portal.
 *
 * Aporta utilidades para el aislamiento, que es lo que mas se prueba:
 *
 *  - `comoUsuario()`, que autentica a una persona y fija su contexto de
 *    tenant en la base. IMPORTANTE: autenticar con `actingAs()` NO fija el
 *    contexto de tenant en la base de datos, porque el middleware
 *    ResolverTenant corre dentro del grupo `web`, que las pruebas no
 *    ejecutan. Por eso, ademas de autenticar, las pruebas fijan el tenant:
 *    si no, la RLS no tendria contra que filtrar.
 *
 *  - `crearPyme()`, que arma una PyME con su administrador y su cliente.
 */
abstract class TestCase extends BaseTestCase
{
    /** Tenant fijado para la prueba en curso. */
    protected ?string $tenantDePrueba = null;

    /**
     * Autentica a un usuario y fija su contexto de tenant en la base.
     */
    protected function comoUsuario(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarTenant($usuario->tenant_id);
    }

    /**
     * Emula lo que hace App\Http\Middleware\ResolverTenant.
     *
     * Se usa `set_config(..., false)`, o sea con alcance de SESION y no de
     * transaccion. En la aplicacion real es al reves (alcance de
     * transaccion, para que el worker de colas no reutilice el tenant de
     * otra PyME). En las pruebas cada caso abre su propia aplicacion y su
     * propia conexion, asi que el valor no sobrevive entre pruebas: por eso
     * el alcance de sesion es seguro aca y hace falta para que las
     * aserciones (que corren fuera de la transaccion del middleware) sigan
     * viendo el mismo contexto.
     */
    protected function fijarTenant(?string $tenantId): static
    {
        if ($tenantId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $tenantId]);

        $this->tenantDePrueba = $tenantId;

        return $this;
    }

    /**
     * Crea una PyME con un administrador y un cliente, y fija su contexto.
     *
     * @return array{tenant: Tenant, administrador: Usuario, cliente: Usuario}
     */
    protected function crearPyme(string $dominio = 'ejemplo.cl'): array
    {
        $tenant = Tenant::factory()->create([
            'dominio_correo' => $dominio,
        ]);

        $this->fijarTenant($tenant->id);

        return [
            'tenant' => $tenant,
            'administrador' => Usuario::factory()->administrador()->de($tenant)->create(),
            'cliente' => Usuario::factory()->de($tenant)->create(),
        ];
    }

    /**
     * Crea una segunda PyME, independiente de la primera, y deja su contexto
     * fijado. Util para comprobar que no se mezclan los datos.
     *
     * @return array{tenant: Tenant, administrador: Usuario, cliente: Usuario}
     */
    protected function crearSegundaPyme(string $dominio = 'otra.cl'): array
    {
        return $this->crearPyme($dominio);
    }
}