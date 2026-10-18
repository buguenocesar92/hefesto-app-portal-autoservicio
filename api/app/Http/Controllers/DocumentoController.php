<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoDocumento;
use App\Http\Resources\DocumentoResource;
use App\Models\Documento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Documentos que la PyME deja disponibles para su cliente.
 *
 * El portal NO emite documentos: solo publica los que la PyME ya emitio y
 * cargo al volumen. Ver docs/09.
 */
class DocumentoController extends Controller
{
    /**
     * Listado de documentos, filtrable por tipo y fecha.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('verListado', Documento::class);

        $usuario = $request->user();

        $consulta = Documento::query()->orderByDesc('emitido_en')->orderByDesc('created_at');

        // Un documento dirigido a una persona concreta (`usuario_id`) es
        // solo de esa persona. El resto es visible para todos los usuarios
        // activos de la PyME.
        if (! $usuario->esAdministrador()) {
            $consulta->where(function ($interna) use ($usuario): void {
                $interna->whereNull('usuario_id')->orWhere('usuario_id', $usuario->id);
            });
        }

        if ($tipo = $this->tipoDelFiltro($request->query('tipo'))) {
            $consulta->where('tipo', $tipo->value);
        }

        if ($desde = $request->query('desde')) {
            $consulta->whereDate('emitido_en', '>=', (string) $desde);
        }

        if ($hasta = $request->query('hasta')) {
            $consulta->whereDate('emitido_en', '<=', (string) $hasta);
        }

        $pagina = $consulta->paginate($this->tamanoDePagina($request))->withQueryString();

        return DocumentoResource::collection($pagina);
    }

    /**
     * Descarga del archivo.
     *
     * El archivo NO se sirve por ruta directa de un servidor web: se autoriza
     * aca y se entrega desde la API. Antes de entregar se comprueba que la
     * ruta real del archivo siga dentro del volumen de documentos: `ruta_archivo`
     * viene de la base de datos y, si alguien escribiera `../../app/.env` ahi,
     * sin esta comprobacion se servirian archivos de fuera.
     */
    public function descargar(Request $request, Documento $documento): BinaryFileResponse
    {
        $this->authorize('descargar', $documento);

        $disco = Storage::disk('documentos');
        $rutaRelativa = $documento->rutaEnDisco();

        if (! $disco->exists($rutaRelativa)) {
            abort(404, 'El archivo de este documento no esta disponible.');
        }

        $rutaReal = realpath($disco->path($rutaRelativa));
        $baseReal = realpath($disco->path(''));

        if ($rutaReal === false || $baseReal === false) {
            abort(404, 'El archivo de este documento no esta disponible.');
        }

        if (! str_starts_with($rutaReal, $baseReal . DIRECTORY_SEPARATOR)) {
            abort(404, 'El archivo de este documento no esta disponible.');
        }

        $respuesta = response()->download(
            $rutaReal,
            $documento->nombre_archivo,
            ['Content-Type' => $documento->mime ?: 'application/octet-stream']
        );

        // Los documentos personales no deben quedar en caches intermedias.
        $respuesta->headers->set('Cache-Control', 'private, no-store');

        return $respuesta;
    }

    private function tipoDelFiltro(mixed $valor): ?TipoDocumento
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return TipoDocumento::tryFrom($valor);
    }

    private function tamanoDePagina(Request $request): int
    {
        $pedido = (int) $request->query('per_page', 15);

        return max(1, min($pedido, 50));
    }
}