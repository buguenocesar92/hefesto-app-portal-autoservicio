<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\DescubridorOidc;
use Illuminate\Console\Command;

/**
 * Descarta la cache del documento de descubrimiento y del JWKS de Authentik.
 *
 * CUANDO SE USA
 * -------------
 * Cuando Authentik rota su clave de firma y la API sigue rechazando tokens
 * validos con el error "La clave ... que firmo el token no esta en el JWKS".
 *
 * Tambien despues de cambiar la URL del emisor en el `.env`.
 *
 *   php artisan portal:olvidar-cache-oidc
 */
class OlvidarCacheOidcCommand extends Command
{
    protected $signature = 'portal:olvidar-cache-oidc';

    protected $description = 'Descarta la cache de descubrimiento OIDC y de claves publicas (JWKS) de Authentik.';

    public function handle(DescubridorOidc $descubridor): int
    {
        $emisor = rtrim((string) config('authentik.issuer'), '/') . '/';

        if ($emisor === '/') {
            $this->error('AUTHENTIK_ISSUER no esta configurado. Revise el archivo .env.');

            return self::FAILURE;
        }

        $descubridor->olvidarCache();

        $this->info('Cache de Authentik descartada para: ' . $emisor);
        $this->line('La proxima entrada al portal volvera a leer el documento de descubrimiento y el JWKS.');

        return self::SUCCESS;
    }
}