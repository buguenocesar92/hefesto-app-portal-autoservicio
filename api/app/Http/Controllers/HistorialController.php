<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\DocumentoResource;
use App\Http\Resources\SolicitudResource;
use App\Models\Documento;
use App\Models\Solicitud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historial de la persona conectada: "que he pedido y que me han entregado".
 *
 * Se devuelve en dos listas y no en una sola mezcla, porque las paginan
 * distinto: un cliente puede tener diez solicitudes y un documento.
 *
 * El cliente ve solo lo suyo; el administrador ve todo el de su PyME, que es
 * lo que le sirve para atender. La RLS ya garantiza que ninguna de las dos
 * listas se salga del tenant.
 */
class HistorialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $esAdministrador = $usuario->esAdministrador();

        $solicitudes = Solicitud::query()
            ->with(['categoria', 'usuario'])
            ->when(! $esAdministrador, fn ($consulta) => $consulta->where('usuario_id', $usuario->id))
            ->orderByDesc('creado_en')
            ->paginate($this->tamanoDePagina($request, 10))
            ->withQueryString();

        $documentos = Documento::query()
            ->when(! $esAdministrador, function ($consulta) use ($usuario): void {
                $consulta->where(function ($interna) use ($usuario): void {
                    $interna->whereNull('usuario_id')->orWhere('usuario_id', $usuario->id);
                });
            })
            ->orderByDesc('emitido_en')
            ->paginate($this->tamanoDePagina($request, 10))
            ->withQueryString();

        $conteo = [
            'solicitudes_totales' => $this->conteoDeSolicitudes($usuario, $esAdministrador),
            'solicitudes_activas' => $this->conteoDeSolicitudes($usuario, $esAdministrador, activas: true),
            'documentos_totales' => $this->conteoDeDocumentos($usuario, $esAdministrador),
        ];

        return response()->json([
            'resumen' => $conteo,
            'solicitudes' => SolicitudResource::collection($solicitudes)->response()->getData(true),
            'documentos' => DocumentoResource::collection($documentos)->response()->getData(true),
        ]);
    }

    private function conteoDeSolicitudes(Usuario $usuario, bool $esAdministrador, bool $activas = false): int
    {
        return Solicitud::query()
            ->when(! $esAdministrador, fn ($consulta) => $consulta->where('usuario_id', $usuario->id))
            ->when($activas, fn ($consulta) => $consulta
                ->whereNotIn('estado', ['cerrada', 'anulada']))
            ->count();
    }

    private function conteoDeDocumentos(Usuario $usuario, bool $esAdministrador): int
    {
        return Documento::query()
            ->when(! $esAdministrador, function ($consulta) use ($usuario): void {
                $consulta->where(function ($interna) use ($usuario): void {
                    $interna->whereNull('usuario_id')->orWhere('usuario_id', $usuario->id);
                });
            })
            ->count();
    }

    private function tamanoDePagina(Request $request, int $porDefecto): int
    {
        $pedido = (int) $request->query('per_page', $porDefecto);

        return max(1, min($pedido, 50));
    }
}