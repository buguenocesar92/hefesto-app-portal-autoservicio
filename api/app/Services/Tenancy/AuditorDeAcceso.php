<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\BitacoraAcceso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Escribe la bitacora de acceso.
 *
 * Los hechos posteriores al login van a la tabla `bitacora_accesos`.
 * Los hechos anteriores al login (estado invalido, token expirado,
 * intento contra un tenant desconocido) NO pueden ir ahi, porque no
 * tienen tenant y la politica RLS lo prohibiria: van al canal de log
 * `portal.seguridad`. Ver docs/08.
 */
class AuditorDeAcceso
{
    /** Acciones que se anotan en la bitacora. */
    public const ACCIONES_ESCRITURA = ['crear', 'actualizar', 'cambiar_estado', 'desactivar'];

    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Registra el desenlace de una peticion autenticada.
     */
    public function registrarPeticion(Request $request, int $codigoEstado): void
    {
        $usuario = auth()->user();

        if ($usuario === null) {
            return;
        }

        $accion = $this->accionDe($request);

        // Si no hay nada que anotar, no se abre una transaccion.
        if ($accion === null) {
            return;
        }

        try {
            $this->contexto->ejecutarAislado($usuario->tenant_id, function () use ($request, $usuario, $accion, $codigoEstado): void {
                BitacoraAcceso::create([
                    'tenant_id' => $usuario->tenant_id,
                    'usuario_id' => $usuario->id,
                    'accion' => $accion,
                    'entidad' => $request->route()?->getName(),
                    'entidad_id' => null,
                    'metodo_http' => $request->method(),
                    'ruta' => mb_substr($request->path(), 0, 255),
                    'direccion_ip' => $request->ip(),
                    'agente_usuario' => mb_substr((string) $request->userAgent(), 0, 255),
                    'exito' => $codigoEstado < 400,
                    'registrado_en' => now(),
                ]);
            });
        } catch (\Throwable $excepcion) {
            // La bitacora NUNCA debe tumbar la peticion del cliente. Si
            // falla, se avisa por log y la respuesta sigue su curso.
            Log::channel('portal_seguridad')->error('No se pudo escribir la bitacora de acceso', [
                'accion' => $accion,
                'error' => $excepcion->getMessage(),
            ]);
        }
    }

    /**
     * Hechos previos al login. Sin tenant, por eso va al log.
     */
    public function registrarEventoDeAcceso(string $evento, array $contexto = []): void
    {
        Log::channel('portal_seguridad')->warning('Acceso al portal: ' . $evento, $contexto);
    }

    private function accionDe(Request $request): ?string
    {
        if ($request->isMethod('get')) {
            return null;
        }

        $ruta = $request->path();

        return match (true) {
            $request->isMethod('post') && str_contains($ruta, '/estado') => 'cambiar_estado',
            $request->isMethod('post') && str_contains($ruta, '/desactivar') => 'desactivar',
            $request->isMethod('post') => 'crear',
            $request->isMethod('patch'), $request->isMethod('put') => 'actualizar',
            default => null,
        };
    }
}