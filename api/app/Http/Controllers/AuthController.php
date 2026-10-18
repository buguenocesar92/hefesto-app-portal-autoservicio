<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UsuarioResource;
use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Auth\ResolvedorTenant;
use App\Services\Auth\VerificadorIdToken;
use App\Services\Tenancy\AuditorDeAcceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Entrada y salida del portal.
 *
 * FLUJO COMPLETO
 * -------------
 * 1. El BFF de Nuxt pide `GET /api/v1/auth/login?slug=panaderia-las-tortas`.
 * 2. Esta API guarda en la sesion el `slug`, un `nonce` y deja que Socialite
 *    genere el `code_verifier` de PKCE; responde con la URL de Authentik.
 * 3. El BFF redirige el navegador ahi. La persona se autentica en Authentik.
 * 4. Authentik devuelve a `GET /api/v1/auth/callback`.
 * 5. Esta API canjea el codigo, VERIFICA el token de identificacion
 *    (VerificadorIdToken), resuelve a que PyME pertenece (ResolvedorTenant),
 *    abre la sesion de Laravel y responde al BFF.
 *
 * Los pasos 1 a 5 ocurren del lado del servidor. El navegador nunca ve un
 * token: la cookie de sesion es HttpOnly.
 */
class AuthController extends Controller
{
    /** Llaves de la sesion que guardan el estado del intento de entrada. */
    private const LLAVE_NONCE = 'oidc_nonce';
    private const LLAVE_SLUG = 'oidc_slug';
    private const LLAVE_DESTINO = 'oidc_destino';
    private const LLAVE_INTENTO = 'oidc_intento_en';

    /** Minutos de validez del intento de entrada. */
    private const VIGENCIA_MINUTOS = 10;

    public function __construct(
        private readonly ResolvedorTenant $resolvedor,
        private readonly VerificadorIdToken $verificador,
        private readonly DescubridorOidc $descubridor,
        private readonly AuditorDeAcceso $auditor,
    ) {}

    /**
     * Paso 1: devuelve la URL de Authentik a la que hay que ir.
     */
    public function login(Request $request): JsonResponse
    {
        $this->exigirConfiguracion();

        $datos = $request->validate([
            // El slug identifica a la PyME en la URL de entrada. Si no
            // viene, ResolvedorTenant lo resuelve por el dominio del correo.
            'slug' => ['nullable', 'string', 'max:48'],
            // A donde volver despues de entrar. Solo se acepta una ruta
            // interna: nunca una URL completa (seria una redireccion
            // abierta, ver docs/08).
            'redirigir_a' => ['nullable', 'string', 'max:255'],
        ]);

        $slug = isset($datos['slug']) ? Str::lower(trim((string) $datos['slug'])) : null;
        $destino = $this->destinoSeguro($datos['redirigir_a'] ?? null);

        // Identificador de sesion nuevo antes de tocar nada: si alguien
        // venia con un identificador reutilizado, no lo aprovechamos.
        $request->session()->regenerate();

        $request->session()->put(self::LLAVE_NONCE, Str::random(40));
        $request->session()->put(self::LLAVE_SLUG, $slug);
        $request->session()->put(self::LLAVE_DESTINO, $destino);
        $request->session()->put(self::LLAVE_INTENTO, time());

        $redirect = $this->proveedor($request)->redirect();

        return response()->json([
            'url' => $redirect->getTargetUrl(),
            'emisor' => $this->verificador->emisor(),
        ]);
    }

    /**
     * Paso 4 y 5: canje del codigo, verificacion del token y apertura de
     * sesion.
     */
    public function callback(Request $request): JsonResponse
    {
        $this->exigirConfiguracion();

        try {
            $proveedor = $this->proveedor($request);

            // Valida `state` y canjea el codigo por los tokens. Lanza
            // InvalidStateException si el state no calza, y eso es correcto:
            // significa que la respuesta no viene de esta peticion.
            $proveedor->user();

            $claims = $this->verificador->verificar(
                $proveedor->idToken(),
                $this->tomarSesion($request, self::LLAVE_NONCE),
            );

            $slug = $this->tomarSesion($request, self::LLAVE_SLUG);
            $destino = $this->tomarSesion($request, self::LLAVE_DESTINO) ?? '/';

            $resuelto = $this->resolvedor->resolver($slug, $claims);
        } catch (Throwable $excepcion) {
            // Un rechazo de entrada es el camino normal, no una excepcion
            // de programacion. Se registra en el canal de seguridad (no
            // en la bitacora: todavia no hay tenant) y se responde 401 con
            // el mensaje que el propio servicio ya formulo para humanos.
            $this->auditor->registrarEventoDeAcceso('entrada_rechazada', [
                'motivo' => $excepcion->getMessage(),
            ]);

            return response()->json([
                'mensaje' => $excepcion->getMessage(),
            ], 401);
        }

        // El guard regenera el identificador de sesion al iniciar sesion.
        // A partir de aqui la peticion sigue siendo anonima: el middleware
        // ResolverTenant ya paso, asi que las consultas que haga esta misma
        // peticion correrian SIN contexto de tenant. Por eso no se consulta
        // nada mas que las tablas del login.
        Auth::login($resuelto['usuario'], remember: false);

        $request->session()->regenerateToken();

        return response()->json([
            'redirigir_a' => $destino,
            'usuario' => UsuarioResource::make($resuelto['usuario']),
            'pymes' => [
                'slug' => $resuelto['tenant']->slug,
                'nombre' => $resuelto['tenant']->nombre,
            ],
        ]);
    }

    /**
     * Cierre de sesion en el portal.
     *
     * La sesion de Authentik NO se cierra aqui a proposito: si se cerrara,
     * la persona tendria que autenticarse de nuevo en el proximo ingreso.
     * El cierre global (SSO) se puede agregar despues usando el endpoint
     * `end_session_endpoint` que declara el documento de descubrimiento.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'mensaje' => 'Sesion cerrada.',
            'emisor' => $this->descubridor->emisor(),
        ]);
    }

    /**
     * Datos de quien esta conectado. La SPA lo llama al arrancar: si
     * responde 401, el usuario tiene que entrar de nuevo.
     */
    public function usuario(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'usuario' => UsuarioResource::make($usuario),
            'pymes' => [
                'slug' => $usuario->tenant->slug,
                'nombre' => $usuario->tenant->nombre,
            ],
        ]);
    }

    /**
     * Construye el proveedor OIDC con la configuracion de Authentik.
     */
    private function proveedor(Request $request): ProveedorOidcAuthentik
    {
        return new ProveedorOidcAuthentik(
            $request,
            (string) config('authentik.client_id'),
            (string) config('authentik.client_secret'),
            (string) config('authentik.redirect'),
            (array) config('authentik.scopes', []),
        );
    }

    /**
     * Falla con un mensaje util si falta la configuracion de Authentik.
     *
     * Sin esto, un `.env` incompleto devolveria un error de Guzzle o una
     * redireccion a una URL vacia: incomprensible para quien instala.
     */
    private function exigirConfiguracion(): void
    {
        $faltantes = [];

        foreach ([
            'issuer' => 'AUTHENTIK_ISSUER',
            'client_id' => 'AUTHENTIK_CLIENT_ID',
            'client_secret' => 'AUTHENTIK_CLIENT_SECRET',
            'redirect' => 'AUTHENTIK_REDIRECT_URI',
        ] as $clave => $variable) {
            if (trim((string) config('authentik.' . $clave)) === '') {
                $faltantes[] = $variable;
            }
        }

        if ($faltantes !== []) {
            throw new RuntimeException(
                'El portal no esta configurado para autenticarse. Faltan estas variables: '
                . implode(', ', $faltantes) . '. Revise api/.env (ver .env.example).'
            );
        }
    }

    /**
     * Lee una llave de la sesion y la borra.
     *
     * El nonce se lee una sola vez: si se pudiera reutilizar, una respuesta
     * vieja de Authentik volveria a servir para entrar de nuevo.
     *
     * Ademas se descarta el intento si paso demasiado tiempo desde que se
     * pidio la entrada: un enlace a medio abrir que alguien deja abierto
     * horas no debe servir para iniciar sesion mas tarde.
     */
    private function tomarSesion(Request $request, string $llave): ?string
    {
        $valor = $request->session()->pull($llave);

        if ($valor === null) {
            return null;
        }

        $inicio = $request->session()->pull(self::LLAVE_INTENTO);

        if (! is_int($inicio) || $inicio + (self::VIGENCIA_MINUTOS * 60) < time()) {
            return null;
        }

        return $valor;
    }

    /**
     * Solo se permite volver a una ruta interna del portal.
     *
     * Se rechazan las URL completas (`https://otro.cl`), las rutas que
     * empiezan con `//` (protocolo relativo) y las que empiezan con `/\`
     * (que algunos navegadores leen como protocolo relativo).
     */
    private function destinoSeguro(?string $destino): string
    {
        $destino = trim((string) $destino);

        if ($destino === '' || ! str_starts_with($destino, '/')) {
            return '/';
        }

        if (str_starts_with($destino, '//') || str_starts_with($destino, '/\\')) {
            return '/';
        }

        return $destino;
    }
}