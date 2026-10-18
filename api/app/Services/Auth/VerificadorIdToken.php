<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use Throwable;

/**
 * Verifica el token de identificacion (ID token) que entrega Authentik.
 *
 * Socialite se encarga del canje del codigo de autorizacion, pero NO de
 * verificar el token de identificacion: por eso esta clase existe y es
 * obligatoria. Sin ella, el portal aceptaria como valido cualquier
 * token, y con el `iss` equivocado de otro proveedor de identidad.
 *
 * Se comprueba, en este orden:
 *
 *  1. Que el token tenga tres segmentos y una firma.
 *  2. Que el algoritmo de la cabecera sea el esperado (`AUTHENTIK_ALGORITMO`).
 *     Esto evita el ataque de confusion de algoritmo, en el que alguien
 *     cambia la cabecera de RS256 a HS256 y firma con la clave publica.
 *  3. Que la clave de verificacion (JWKS) corresponda al `kid` del token.
 *  4. Firma valida (la verifica `JWT::decode`).
 *  5. `iss` exactamente igual al emisor configurado.
 *  6. `aud` contiene el identificador de cliente.
 *  7. `nonce` igual al que se genero en esta misma sesion.
 *  8. `exp` en el futuro (con tolerancia de reloj).
 *
 * @see https://docs.goauthentik.io/add-secure-apps/providers/oauth2/
 */
class VerificadorIdToken
{
    public function __construct(private readonly DescubridorOidc $descubridor) {}

    /**
     * @return array{
     *     sub: string,
     *     correo: ?string,
     *     correo_verificado: bool,
     *     nombre: ?string,
     *     usuario_preferido: ?string,
     *     grupos: list<string>
     * }
     */
    public function verificar(string $idToken, ?string $nonceEsperado): array
    {
        $partes = explode('.', $idToken);

        if (count($partes) !== 3) {
            throw new RuntimeException('El token de identificacion no tiene el formato de un JWT.');
        }

        [$cabeceraCruda, $cargaCruda, $firmaCruda] = $partes;

        if ($firmaCruda === '') {
            throw new RuntimeException('El token de identificacion no viene firmado.');
        }

        $cabecera = $this->decodificarSegmento($cabeceraCruda);
        $algoritmoEsperado = (string) config('authentik.algoritmo', 'RS256');

        if (($cabecera['alg'] ?? null) !== $algoritmoEsperado) {
            throw new RuntimeException(
                'El token de identificacion fue firmado con el algoritmo '
                . ($cabecera['alg'] ?? '(desconocido)') . ' y se esperaba ' . $algoritmoEsperado . '.'
            );
        }

        $kid = $cabecera['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw new RuntimeException('El token de identificacion no indica que clave lo firmo (kid).');
        }

        // Margen de reloj entre Authentik y la API.
        JWT::$leeway = ((int) config('authentik.tolerancia_minutos', 2)) * 60;

        try {
            $claves = JWK::parseKeySet($this->descubridor->jwks(), $algoritmoEsperado);
        } catch (Throwable $excepcion) {
            throw new RuntimeException(
                'No se pudo leer el conjunto de claves de Authentik: ' . $excepcion->getMessage(),
                previous: $excepcion
            );
        }

        $clave = $claves[$kid] ?? null;

        if ($clave === null) {
            throw new RuntimeException(
                'La clave ' . $kid . ' que firmo el token no esta en el JWKS de Authentik. '
                . 'Puede que Authentik haya rotado sus claves: ejecute "php artisan portal:olvidar-cache-oidc".'
            );
        }

        try {
            $claims = JWT::decode($idToken, $clave);
        } catch (Throwable $excepcion) {
            throw new RuntimeException(
                'El token de identificacion no supero la verificacion: ' . $excepcion->getMessage(),
                previous: $excepcion
            );
        }

        $this->verificarEmisor($claims->iss ?? null);
        $this->verificarAudiencia($claims->aud ?? null, $claims->azp ?? null);
        $this->verificarNonce($claims->nonce ?? null, $nonceEsperado);
        $this->verificarExpiracion($claims->exp ?? null);

        $grupos = $claims->groups ?? [];

        return [
            'sub' => (string) ($claims->sub ?? ''),
            'correo' => isset($claims->email) ? (string) $claims->email : null,
            // Authentik entrega `true` por defecto; desde 2025.10 el claim
            // `email_verified` vale false salvo que se configure el flujo
            // que lo verifica. Por eso NO se usa como unico criterio.
            'correo_verificado' => (bool) ($claims->email_verified ?? false),
            'nombre' => isset($claims->name) ? (string) $claims->name : null,
            'usuario_preferido' => isset($claims->preferred_username) ? (string) $claims->preferred_username : null,
            'grupos' => is_array($grupos) ? array_values(array_map('strval', $grupos)) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function decodificarSegmento(string $segmento): array
    {
        $json = base64_decode(strtr($segmento, '-_', '+/'), true);

        if ($json === false) {
            throw new RuntimeException('El token de identificacion tiene un segmento ilegible.');
        }

        $datos = json_decode($json, true);

        return is_array($datos) ? $datos : [];
    }

    private function verificarEmisor(mixed $issuer): void
    {
        $esperado = rtrim((string) config('authentik.issuer'), '/') . '/';

        if (! is_string($issuer) || rtrim($issuer, '/') . '/' !== $esperado) {
            throw new RuntimeException(
                'El token de identificacion no fue emitido por este Authentik.'
            );
        }
    }

    private function verificarAudiencia(mixed $audience, mixed $authorizedParty): void
    {
        $clientId = (string) config('authentik.client_id');

        if (is_string($audience)) {
            if (! hash_equals($clientId, $audience)) {
                throw new RuntimeException('El token de identificacion no es para esta aplicacion.');
            }

            return;
        }

        if (! is_array($audience) || ! in_array($clientId, $audience, true)) {
            throw new RuntimeException('El token de identificacion no es para esta aplicacion.');
        }

        // Con varias audiencias, `azp` debe identificar al cliente real.
        if (count($audience) > 1 && (! is_string($authorizedParty) || ! hash_equals($clientId, $authorizedParty))) {
            throw new RuntimeException('El token de identificacion declara varias audiencias sin un azp valido.');
        }
    }

    private function verificarNonce(mixed $nonce, ?string $esperado): void
    {
        if ($esperado === null || $esperado === '') {
            throw new RuntimeException(
                'No hay nonce en la sesion: se rechaza el canje para no aceptar un token de otra peticion.'
            );
        }

        if (! is_string($nonce) || ! hash_equals($esperado, $nonce)) {
            throw new RuntimeException('El nonce del token no corresponde a esta sesion.');
        }
    }

    private function verificarExpiracion(mixed $exp): void
    {
        if (! is_int($exp) && ! is_float($exp)) {
            throw new RuntimeException('El token de identificacion no declara expiracion.');
        }

        $tolerancia = ((int) config('authentik.tolerancia_minutos', 2)) * 60;

        if ((float) $exp + $tolerancia < (float) time()) {
            throw new RuntimeException('El token de identificacion esta vencido.');
        }
    }

    /**
     * Emisor que se puede mostrar en la interfaz al usuario.
     */
    public function emisor(): string
    {
        return rtrim((string) config('authentik.issuer'), '/') . '/';
    }
}