<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActualizarCategoriaRequest;
use App\Http\Requests\Admin\StoreCategoriaRequest;
use App\Http\Resources\CategoriaResource;
use App\Models\CategoriaServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Categorias de servicio que ofrece la PyME.
 *
 * Cada categoria define que datos extras pide al abrir una solicitud (ver
 * SolicitudController::validarCamposDinamicos). Los clientes solo ven las
 * activas; la PyME ve todas.
 */
class CategoriaController extends Controller
{
    /**
     * Listado para la administracion de la PyME: incluye las inactivas.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('administrar', CategoriaServicio::class);

        $consulta = CategoriaServicio::query()->orderBy('nombre');

        if ($request->has('activa')) {
            $consulta->where('activa', $request->boolean('activa'));
        }

        return CategoriaResource::collection($consulta->get());
    }

    /**
     * Listado para el cliente final: solo las activas.
     */
    public function indexPublico(Request $request): AnonymousResourceCollection
    {
        $consulta = CategoriaServicio::query()
            ->where('activa', true)
            ->orderBy('nombre')
            ->get();

        return CategoriaResource::collection($consulta);
    }

    public function store(StoreCategoriaRequest $request): JsonResponse
    {
        $this->authorize('crear', CategoriaServicio::class);

        $datos = $request->validated();

        $categoria = CategoriaServicio::create([
            'tenant_id' => $request->user()->tenant_id,
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'campos' => $datos['campos'] ?? null,
            'tiempo_respuesta_sugerido' => $datos['tiempo_respuesta_sugerido'] ?? null,
            'activa' => $datos['activa'] ?? true,
        ]);

        return CategoriaResource::make($categoria)
            ->response()
            ->setStatusCode(201);
    }

    public function update(ActualizarCategoriaRequest $request, CategoriaServicio $categoria): CategoriaResource
    {
        $this->authorize('actualizar', $categoria);

        $datos = $request->validated();

        // Solo se tocan las claves que vieram en el cuerpo de la peticion:
        // un PATCH parcial no debe borrar campos que el formulario no mando.
        $categoria->forceFill(array_intersect_key($datos, array_flip([
            'nombre',
            'descripcion',
            'campos',
            'tiempo_respuesta_sugerido',
            'activa',
        ])))->save();

        return CategoriaResource::make($categoria->refresh());
    }
}