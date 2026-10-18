<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * Proveedor OIDC para Authentik sobre Socialite.
 *
 * POR QUE ES CODIGO PROPIO Y NO UN PAQUETE
 * -----------------------------------------
 * En Socialite v5.31.0 no existe un controlador OIDC generico: en
 * `src/Two` solo hay controladores de proveedores concretos
 * (Google, GitHub, GitLab, Slack, LinkedIn, Twitch, X, Facebook,
 * Bitbucket) mas `AbstractProvider`. Por eso este clase extiende
 * `AbstractProvider` e implementa el flujo de codigo de autorizacion
 * de OIDC contra los endpoints que declara el documento de
 * descubrimiento de Authentik.
 *
 * PKCE
 * ----
 * `AbstractProvider::redirect()` genera el `code_verifier`, lo deja en la
 * sesion y arma el `code_challenge` con `getCodeChallenge()`, que hashea
 * `session('code_verifier')`. El metodo `getTokenFields()` lo vuelve a leer
 * con `pull()` al canjear el codigo. O sea: el verificador lo genera Socialite
 * y no este codigo ni el controlador. Se usa S256, que es lo que devuelve
 * `getCodeChallengeMethod()`.
 *
 * Flujo implementado: authorization code + PKCE. No se usa el flujo
 * implicito: la propia documentacion de Authentik lo marca como legado.
 *
 * @see https://docs.goauthentik.io/add-secure-apps/providers/oauth2/
 */
class ProveedorOidcAuthentik extends AbstractProvider implements ProviderInterface
{
    /** @var list<string> */
    protected $scopes = ['openid', 'profile', 'email'];

    /** OIDC usa espacio como separador de alcances (RFC 6749). */
    protected $scopeSeparator = ' ';

    private DescubridorOidc $descubridor;

    /**
     * Token de identificacion (ID token) de la ultima respuesta de token.
     *
     * Hace falta porque Socialite lo tira: en `AbstractProvider::user()` la
     * respuesta del endpoint de token se pasa a `userInstance()`, y ahi
     * `User::setToken()` recibe SOLO el `access_token`. El `id_token` se
     * queda en el arreglo de respuesta y se pierde. Sin recuperarlo, no se
     * podria verificar `iss`, `aud` ni `nonce` (ver VerificadorIdToken),
     * que son justamente las comprobaciones que hacen segura la entrada.
     */
    private ?string $idToken = null;

    public function __construct(
        $request,
        $clientId,
        $clientSecret,
        $redirectUrl,
        array $scopes = [],
        ?DescubridorOidc $descubridor = null
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl);

        if ($scopes !== []) {
            $this->scopes = $scopes;
        }

        // Activa PKCE en la clase base (code_challenge + code_verifier).
        $this->usesPKCE = true;

        $this->descubridor = $descubridor ?? app(DescubridorOidc::class);
    }

    /**
     * URL a la que se redirige al usuario para autenticarse en Authentik.
     *
     * Se anade `nonce` (OBLIGATORIO en OIDC): se usa despues para
     * comprobar que el token de identificacion corresponde a esta misma
     * peticion y no a una respuesta inyectada.
     */
    protected function getAuthUrl($state): string
    {
        $url = $this->descubridor->endpointAutorizacion();

        $campos = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'state' => $state,
            'code_challenge' => $this->getCodeChallenge(),
            'code_challenge_method' => $this->getCodeChallengeMethod(),
        ];

        $nonce = request()->session()->get('oidc_nonce');

        if (is_string($nonce) && $nonce !== '') {
            $campos['nonce'] = $nonce;
        }

        return $url . '?' . http_build_query($campos, '', '&', $this->encodingType);
    }

    /** Endpoint de token, tomado del documento de descubrimiento. */
    protected function getTokenUrl(): string
    {
        return $this->descubridor->endpointToken();
    }

    /**
     * `AbstractProvider::getTokenFields()` ya envia grant_type, client_id,
     * client_secret, code, redirect_uri y el code_verifier de PKCE. Se
     * anaden los campos que un servidor OIDC espera ademas.
     */
    protected function getTokenFields($code): array
    {
        return array_merge(
            parent::getTokenFields($code),
            ['scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator)]
        );
    }

    /**
     * Datos del usuario leidos del endpoint `userinfo`.
     *
     * Nota: los claims que se TIENEN que validar (issuer, audiencia,
     * nonce, expiracion) se validan aparte, en VerificadorIdToken, sobre
     * el token de identificacion. El endpoint userinfo se consulta solo
     * para refreshing datos de perfil.
     */
    protected function getUserByToken($token): array
    {
        $respuesta = $this->getHttpClient()->get($this->descubridor->endpointUserInfo(), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return json_decode((string) $respuesta->getBody(), true) ?: [];
    }

    /**
     * Traduce la respuesta de Authentik al objeto de Socialite.
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $user['picture'] ?? null,
        ]);
    }

    /**
     * Intercepta la respuesta del endpoint de token para conservar el
     * `id_token` antes de que Socialite la descarte.
     *
     * Se conserva el comportamiento de la clase base: se llama a
     * `parent::userInstance()` y se devuelve el mismo objeto de Socialite.
     */
    protected function userInstance(array $response, array $user)
    {
        $idToken = $response['id_token'] ?? null;

        $this->idToken = is_string($idToken) && $idToken !== '' ? $idToken : null;

        return parent::userInstance($response, $user);
    }

    /**
     * Token de identificacion de la ultima operacion, si lo entrego Authentik.
     *
     * @throws RuntimeException si Authentik no devolvio `id_token`. Es un
     *         fallo de configuracion del proveedor (no se pidio el alcance
     *         `openid`), no algo que deba tolerarse en silencio.
     */
    public function idToken(): string
    {
        if ($this->idToken === null) {
            throw new RuntimeException(
                'Authentik no devolvio un token de identificacion (id_token). '
                . 'Revise que el alcance "openid" este habilitado en el proveedor OAuth2 de Authentik.'
            );
        }

        return $this->idToken;
    }

    /**
     * Claim `groups` de Authentik, que no existe en el objeto de Socialite.
     * Llega con el alcance `profile` segun la documentacion oficial.
     */
    public function gruposDe(array $usuarioBruto): array
    {
        $grupos = $usuarioBruto['groups'] ?? [];

        return is_array($grupos) ? array_values(array_map('strval', $grupos)) : [];
    }

    public function emisorEsperado(): string
    {
        return rtrim((string) config('authentik.issuer'), '/') . '/';
    }
}