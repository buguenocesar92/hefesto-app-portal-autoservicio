<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActualizarUsuarioRequest;
use App\Http\Requests\Admin\StoreInvitacionRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Invitacion;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administracion de las personas de la PyME.
 *
 * QUE HACE LA AUTENTICACION Y QUE HACE ESTO
 * ------------------------------------------
 * Authentik decide QUIEN eres (la identidad). Este controlador decide QUE
 * PUEDES HACER dentro de tu PyME (el permiso). Por eso el rol se guarda aca y
 * no se lee del grupo de Authentik en cada peticion: el grupo solo sirvio
 * para el primer alta. Ver docs/02, seccion "Identidad y permiso".
 *
 * Nota sobre invitaciones: este controlador NO crea la persona en Authentik.
 * Crea un registro que autoriza un correo. La alta en Authentik la hace la
 * propia PyME o un flujo de Authentik. El portal tampoco envia correos en este
 * primer corte: el token se devuelve una sola vez para que la PyME lo entregue
 * por el canal que quiera.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('verListado', Usuario::class);

        $consulta = Usuario::query()->orderByDesc('activo')->orderBy('nombre');

        if ($request->has('activo')) {
            $consulta->where('activo', $request->boolean('activo'));
        }

        if ($rol = $request->query('rol')) {
            $consulta->where('rol', (string) $rol);
        }

        $busqueda = trim((string) $request->query('q', ''));

        if ($busqueda !== '') {
            $consulta->where(function ($interna) use ($busqueda): void {
                $interna->where('nombre', 'ilike', '%' . $busqueda . '%')
                    ->orWhere('apellido', 'ilike', '%' . $busqueda . '%')
                    ->orWhere('correo', 'ilike', '%' . $busqueda . '%');
            });
        }

        $pagina = $consulta->paginate(25)->withQueryString();

        return UsuarioResource::collection($pagina);
    }

    /**
     * Crea una invitacion para un correo.
     */
    public function store(StoreInvitacionRequest $request): JsonResponse
    {
        $this->authorize('crear', Usuario::class);

        $datos = $request->validated();
        $correo = Str::lower(trim($datos['correo']));
        $quienInvita = $request->user();

        // Si esa persona ya existe en la PyME, invitar no aporta nada.
        if (Usuario::where('correo', $correo)->exists()) {
            throw ValidationException::withMessages([
                'correo' => 'Ese correo ya tiene un usuario en esta PyME.',
            ]);
        }

        // Se genera el token en claro y se guarda SOLO su hash SHA-256.
        // El token en claro no se persiste: por eso se devuelve aca, una
        // unica vez, y despues ya no se puede recuperar.
        $token = Str::random(48);

        $invitacion = Invitacion::create([
            'tenant_id' => $quienInvita->tenant_id,
            'correo' => $correo,
            'rol' => RolUsuario::from($datos['rol'] ?? RolUsuario::Cliente->value),
            'token_hash' => Invitacion::hashearToken($token),
            'invitado_por' => $quienInvita->id,
            'expira_en' => now()->addDays(30),
        ]);

        return response()->json([
            'invitacion' => [
                'id' => $invitacion->id,
                'correo' => $invitacion->correo,
                'rol' => $invitacion->rol->value,
                'expira_en' => $invitacion->expira_en?->toIso8601String(),
            ],
            'token' => $token,
            'aviso' => 'El token se muestra una sola vez. Entregalo a la persona; despues no se puede recuperar.',
        ], 201);
    }

    /**
     * Cambia el rol o el telefono de un usuario de la PyME.
     */
    public function update(ActualizarUsuarioRequest $request, Usuario $usuario): UsuarioResource
    {
        $this->authorize('actualizar', $usuario);

        $datos = $request->validated();
        $rol = isset($datos['rol']) ? RolUsuario::from($datos['rol']) : null;

        // Quitarle el rol de administrador a la ultima persona que lo tiene
        // deja a la PyME sin nadie que administre el portal. Se rechaza.
        if ($rol !== null && ! $rol->equals($usuario->rol) && ! $this->quedaOtroAdministrador($usuario)) {
            throw ValidationException::withMessages([
                'rol' => 'No se puede quitar el rol de administrador a la ultima administradora o administrador de la PyME.',
            ]);
        }

        $cambios = [];

        if ($rol !== null) {
            $cambios['rol'] = $rol;
        }

        if (array_key_exists('telefono', $datos)) {
            $cambios['telefono'] = $datos['telefono'];
        }

        $usuario->forceFill($cambios)->save();

        return UsuarioResource::make($usuario->refresh());
    }

    /**
     * Desactiva a un usuario.
     *
     * No se borra nunca a una persona: sus solicitudes y documentos quedan
     * con su nombre y el historial sigue siendo real. La cuenta deja de
     * entrar al portal y la sesion abierta se invalida en el proximo uso.
     */
    public function desactivar(Request $request, Usuario $usuario): JsonResponse
    {
        $this->authorize('desactivar', $usuario);

        if ($usuario->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'usuario' => 'No te puedes desactivar a ti mismo. Pide que lo haga otra persona.',
            ]);
        }

        if (! $this->quedaOtroAdministrador($usuario)) {
            throw ValidationException::withMessages([
                'usuario' => 'No se puede desactivar a la ultima administradora o administrador de la PyME.',
            ]);
        }

        $usuario->forceFill(['activo' => false])->save();

        return response()->json([
            'mensaje' => 'Usuario desactivado.',
            'usuario' => UsuarioResource::make($usuario->refresh()),
        ]);
    }

    /**
     * Queda al menos OTRO administrador activo despues de tocar a `$usuario`.
     *
     * Se cuenta a los demás, no al propio usuario: la pregunta que importa es
     * si la PyME puede seguir administrando su portal sin la persona a la que
     * se le esta quitando el rol o se la esta desactivando.
     */
    private function quedaOtroAdministrador(Usuario $usuario): bool
    {
        return Usuario::where('rol', RolUsuario::Administrador->value)
            ->where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->exists();
    }
}