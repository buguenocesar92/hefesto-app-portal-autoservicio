<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lee el documento de descubrimiento OpenID Connect de Authentik.
 *
 * Rutas verificadas en la documentacion oficial de Authentik
 * (https://docs.goauthentik.io/add-secure-apps/providers/oauth2/):
 *
 *   OpenID Configuration  /application/o/<slug>/.well-known/openid-configuration
 *   JWKS                   /application/o/<slug>/jwks/
 *   End Session            /application/o/<slug>/end-session/
 *   Authorization          /application/o/authorize/
 *   Token                  /application/o/token/
 *   User Info              /application/o/userinfo/
 *
 * No se escriben a mano en el codigo: se leen del documento. Asi, si
 * Authentik cambia una ruta, el portal no se rompe.
 *
 * El resultado se cachea en Redis (`Cache::remember`), que es la misma
 * cache que usa el resto de la aplicacion.
 */
class DescubridorOidc
{
    /** @var array<string, string>|null */
    private ?array $documento = null;

    /** @return array<string, string> */
    public function documento(): array
    {
        if ($this->documento !== null) {
            return $this->documento;
        }

        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        $this->documento = Cache::remember(
            'oidc:descubrimiento:' . sha1($emisor),
            (int) config('authentik.cache_segundos', 3600),
            function () use ($emisor): array {
                $respuesta = Http::timeout(10)
                    ->retry(2, 200)
                    ->acceptJson()
                    ->get($emisor . '.well-known/openid-configuration');

                if (! $respuesta->successful()) {
                    throw new RuntimeException(
                        'No se pudo leer el documento de descubrimiento de Authentik en '
                        . $emisor . '.well-known/openid-configuration (HTTP '
                        . $respuesta->status() . ').'
                    );
                }

                return $respuesta->json();
            }
        );

        return $this->documento;
    }

    public function endpoint(string $clave): string
    {
        $documento = $this->documento();

        if (! isset($documento[$clave]) || ! is_string($documento[$clave])) {
            throw new RuntimeException(
                'El documento de descubrimiento de Authentik no declara el endpoint "' . $clave . '". '
                . 'Revise la version de Authentik y la URL del emisor.'
            );
        }

        return $documento[$clave];
    }

    public function endpointAutorizacion(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    public function endpointToken(): string
    {
        return $this->endpoint('token_endpoint');
    }

    public function endpointUserInfo(): string
    {
        return $this->endpoint('userinfo_endpoint');
    }

    public function endpointJwks(): string
    {
        return $this->endpoint('jwks_uri');
    }

    public function endpointCierreSesion(): ?string
    {
        $documento = $this->documento();

        return isset($documento['end_session_endpoint']) && is_string($documento['end_session_endpoint'])
            ? $documento['end_session_endpoint']
            : null;
    }

    /** Emisor declarado por el propio documento de descubrimiento. */
    public function emisor(): string
    {
        return (string) $this->endpoint('issuer');
    }

    /**
     * Conjunto de claves publicas (JWKS) para verificar los tokens.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jwks(): array
    {
        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        return Cache::remember(
            'oidc:jwks:' . sha1($emisor),
            (int) config('authentik.cache_segundos', 3600),
            function (): array {
                $respuesta = Http::timeout(10)
                    ->retry(2, 200)
                    ->acceptJson()
                    ->get($this->endpointJwks());

                if (! $respuesta->successful()) {
                    throw new RuntimeException(
                        'No se pudo leer el JWKS de Authentik (HTTP ' . $respuesta->status() . ').'
                    );
                }

                return $respuesta->json('keys') ?? [];
            }
        );
    }

    /**
     * Descarta la cache de descubrimiento y JWKS.
     *
     * Se usa cuando Authentik rota su clave de firma y la API sigue
     * contre la clave anterior. Ver docs/05, seccion "Rotacion de claves".
     */
    public function olvidarCache(): void
    {
        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        Cache::forget('oidc:descubrimiento:' . sha1($emisor));
        Cache::forget('oidc:jwks:' . sha1($emisor));

        $this->documento = null;
    }
}