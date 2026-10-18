<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoSolicitud;
use App\Enums\PrioridadSolicitud;
use App\Http\Requests\Solicitudes\CambiarEstadoSolicitudRequest;
use App\Http\Requests\Solicitudes\StoreEventoSolicitudRequest;
use App\Http\Requests\Solicitudes\StoreSolicitudRequest;
use App\Http\Resources\SolicitudEventoResource;
use App\Http\Resources\SolicitudResource;
use App\Models\CategoriaServicio;
use App\Models\Solicitud;
use App\Models\SolicitudEvento;
use App\Services\Solicitudes\GeneradorDeFolio;
use App\Services\Solicitudes\MaquinaDeEstadosSolicitud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Solicitudes de servicio: el modulo central del portal.
 *
 * QUIEN VE QUE
 * ------------
 * El cliente ve y comenta SOLO lo que el mismo creo. El administrador de la
 * PyME ve todas las de su PyME y es el unico que puede cambiar el estado.
 * Las reglas estan en SolicitudPolicy, no en este archivo: aca queda la
 * logica de negocio.
 *
 * DOBLA DEFENSA
 * -------------
 * La politica `SolicitudPolicy` decide, pero ademas la base de datos filtra:
 * la peticion corre dentro de la transaccion que abre el middleware
 * ResolverTenant, con el contexto de tenant ya fijado. Un `id` de otra PyME
 * no se encuentra (404), y aunque se encontrara, la RLS no lo devolveria.
 */
class SolicitudController extends Controller
{
    /** Tipos admitidos en los campos dinamicos de una categoria. */
    private const TIPOS_CAMPO = ['texto', 'numero', 'fecha', 'seleccion', 'booleano'];

    public function __construct(
        private readonly GeneradorDeFolio $folios,
        private readonly MaquinaDeEstadosSolicitud $maquina,
    ) {}

    /**
     * Listado de solicitudes con filtros y paginacion.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('verListado', Solicitud::class);

        $usuario = $request->user();

        $consulta = Solicitud::query()
            ->with(['categoria', 'usuario'])
            ->orderByDesc('creado_en');

        // El cliente final nunca ve solicitudes de otras personas de su
        // misma PyME: solo las suyas.
        if (! $usuario->esAdministrador()) {
            $consulta->where('usuario_id', $usuario->id);
        }

        if ($estado = $this->estadoDelFiltro($request->query('estado'))) {
            $consulta->where('estado', $estado->value);
        }

        $busqueda = trim((string) $request->query('q', ''));

        if ($busqueda !== '') {
            $consulta->where(function ($interna) use ($busqueda): void {
                $interna->where('titulo', 'ilike', '%' . $busqueda . '%')
                    ->orWhere('folio', 'ilike', '%' . $busqueda . '%');
            });
        }

        $pagina = $consulta->paginate($this->tamanoDePagina($request))->withQueryString();

        return SolicitudResource::collection($pagina);
    }

    /**
     * Alta de solicitud. La corre el cliente final.
     */
    public function store(StoreSolicitudRequest $request): JsonResponse
    {
        $this->authorize('crear', Solicitud::class);

        $datos = $request->validated();
        $usuario = $request->user();

        $categoria = isset($datos['categoria_id'])
            ? CategoriaServicio::find($datos['categoria_id'])
            : null;

        $this->validarCamposDinamicos($categoria, $datos['datos'] ?? []);

        // El folio se calcula dentro de la misma transaccion que el INSERT
        // (la que abrio el middleware de tenant), con la fila del contador
        // bloqueada. Ver GeneradorDeFolio.
        $solicitud = Solicitud::create([
            'tenant_id' => $usuario->tenant_id,
            'folio' => $this->folios->siguiente($usuario->tenant_id),
            'usuario_id' => $usuario->id,
            'categoria_id' => $categoria?->id,
            'titulo' => $datos['titulo'],
            'descripcion' => $datos['descripcion'],
            'estado' => EstadoSolicitud::Recibida,
            'prioridad' => PrioridadSolicitud::from($datos['prioridad']),
            'datos' => $datos['datos'] ?? null,
        ]);

        // La primera entrada de la bitacora: "recibida".
        SolicitudEvento::create([
            'tenant_id' => $usuario->tenant_id,
            'solicitud_id' => $solicitud->id,
            'usuario_id' => $usuario->id,
            'estado_anterior' => null,
            'estado_nuevo' => EstadoSolicitud::Recibida,
            'comentario' => 'Solicitud abierta por el cliente.',
            'registrado_en' => now(),
        ]);

        return SolicitudResource::make($solicitud->load(['categoria', 'usuario']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Detalle de una solicitud.
     */
    public function show(Request $request, Solicitud $solicitud): SolicitudResource
    {
        $this->authorize('ver', $solicitud);

        return SolicitudResource::make($solicitud->load(['categoria', 'usuario']));
    }

    /**
     * Bitacora de la solicitud (cambios de estado y comentarios).
     */
    public function eventos(Request $request, Solicitud $solicitud): AnonymousResourceCollection
    {
        $this->authorize('ver', $solicitud);

        $eventos = $solicitud->eventos()
            ->with('usuario')
            ->orderBy('registrado_en')
            ->paginate($this->tamanoDePagina($request))
            ->withQueryString();

        return SolicitudEventoResource::collection($eventos);
    }

    /**
     * Comentario del cliente (o de la PyME) en una solicitud.
     *
     * No cambia el estado. La tabla `solicitud_eventos` exige `estado_nuevo`,
     * asi que el comentario se registra con el estado actual de la solicitud
     * y `estado_anterior` en NULL: eso lo distingue de un cambio de estado.
     */
    public function agregarEvento(
        StoreEventoSolicitudRequest $request,
        Solicitud $solicitud,
    ): JsonResponse {
        $this->authorize('comentar', $solicitud);

        $comentario = $request->validated()['comentario'];

        $evento = SolicitudEvento::create([
            'tenant_id' => $solicitud->tenant_id,
            'solicitud_id' => $solicitud->id,
            'usuario_id' => $request->user()->id,
            'estado_anterior' => null,
            'estado_nuevo' => $solicitud->estado,
            'comentario' => $comentario,
            'registrado_en' => now(),
        ]);

        return SolicitudEventoResource::make($evento->load('usuario'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Cambio de estado. Solo el administrador de la PyME.
     */
    public function cambiarEstado(
        CambiarEstadoSolicitudRequest $request,
        Solicitud $solicitud,
    ): JsonResponse {
        $this->authorize('cambiarEstado', $solicitud);

        $datos = $request->validated();
        $nuevo = EstadoSolicitud::from($datos['estado']);
        $anterior = $solicitud->estado;

        // El mensaje de "transicion no permitida" lo redacta la maquina de
        // estados; aca solo se traduce el fallo a un error de validacion,
        // para que la SPA lo muestre como error de formulario y no como
        // error del servidor.
        try {
            $this->maquina->exigirTransicion($anterior, $nuevo);
        } catch (RuntimeException $excepcion) {
            throw ValidationException::withMessages(['estado' => $excepcion->getMessage()]);
        }

        $cambios = ['estado' => $nuevo];

        if (array_key_exists('asignado_a', $datos)) {
            $cambios['asignado_a'] = $datos['asignado_a'];
        }

        if ($nuevo->esTerminal()) {
            $cambios['cerrada_en'] = $solicitud->cerrada_en ?? now();
        }

        $solicitud->forceFill($cambios)->save();

        SolicitudEvento::create([
            'tenant_id' => $solicitud->tenant_id,
            'solicitud_id' => $solicitud->id,
            'usuario_id' => $request->user()->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'comentario' => $datos['comentario'] ?? null,
            'registrado_en' => now(),
        ]);

        return SolicitudResource::make($solicitud->fresh()->load(['categoria', 'usuario']))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Valida `datos` contra la definicion de campos de la categoria.
     *
     * La categoria define que datos extras pide la PyME (por ejemplo: numero
     * de serie, direccion, franja horaria). Validarlos aca evita guardar
     * solicitudes con datos que nadie va a poder leer.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function validarCamposDinamicos(?CategoriaServicio $categoria, array $datos): void
    {
        $definidos = $categoria?->campos ?? [];

        if ($definidos === []) {
            // Sin campos definidos, cualquier dato extra es ruido.
            if ($datos !== []) {
                throw ValidationException::withMessages([
                    'datos' => 'Esta categoria de servicio no pide datos adicionales.',
                ]);
            }

            return;
        }

        $errores = [];

        foreach ($definidos as $campo) {
            if (! is_array($campo) || ! isset($campo['clave'])) {
                continue;
            }

            $clave = (string) $campo['clave'];
            $requerido = (bool) ($campo['requerido'] ?? false);
            $valor = $datos[$clave] ?? null;

            if (($valor === null || $valor === '') && $requerido) {
                $errores['datos.' . $clave] = sprintf(
                    'El campo "%s" es obligatorio.',
                    (string) ($campo['etiqueta'] ?? $clave)
                );

                continue;
            }

            if ($valor === null || $valor === '') {
                continue;
            }

            $tipo = (string) ($campo['tipo'] ?? 'texto');

            if (! in_array($tipo, self::TIPOS_CAMPO, true)) {
                continue;
            }

            $problema = $this->problemaConElValor($tipo, $valor, $campo);

            if ($problema !== null) {
                $errores['datos.' . $clave] = $problema;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * @param  array<string, mixed>  $campo
     */
    private function problemaConElValor(string $tipo, mixed $valor, array $campo): ?string
    {
        return match ($tipo) {
            'numero' => is_numeric($valor) ? null : 'Debe ser un numero.',
            'fecha' => $this->pareceFecha($valor)
                ? null
                : 'Debe ser una fecha (por ejemplo, 2026-03-14).',
            'booleano' => is_bool($valor) || in_array($valor, [0, 1, '0', '1'], true)
                ? null
                : 'Debe ser si o no.',
            'seleccion' => in_array((string) $valor, array_map('strval', $campo['opciones'] ?? []), true)
                ? null
                : 'El valor no esta entre las opciones permitidas.',
            default => is_string($valor) ? null : 'Debe ser texto.',
        };
    }

    private function pareceFecha(mixed $valor): bool
    {
        if (! is_string($valor)) {
            return false;
        }

        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha !== false && $fecha->format('Y-m-d') === $valor;
    }

    /**
     * Estado del filtro, validado contra el catalogo.
     */
    private function estadoDelFiltro(mixed $valor): ?EstadoSolicitud
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return EstadoSolicitud::tryFrom($valor);
    }

    /**
     * Tamano de pagina acotado, para que nadie pida 10000 filas.
     */
    private function tamanoDePagina(Request $request): int
    {
        $pedido = (int) $request->query('per_page', 15);

        return max(1, min($pedido, 50));
    }
}