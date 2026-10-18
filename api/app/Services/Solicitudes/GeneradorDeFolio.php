<?php

declare(strict_types=1);

namespace App\Services\Solicitudes;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera el folio visible de una solicitud (por ejemplo `SOL-1042`).
 *
 * El folio es unico POR TENANT: cada PyME numera desde 1. Para que dos
 * altas simultaneas no repitan folio, se bloquea la fila del contador
 * con `SELECT ... FOR UPDATE` dentro de la misma transaccion que el
 * INSERT. El indice unico (tenant_id, folio) es la ultima red: si algo
 * se escapara, la base rechazaria el INSERT en vez de duplicar.
 *
 * Por eso este metodo DEBE correr dentro de la transaccion que crea la
 * solicitud, con el contexto de tenant ya fijado.
 */
class GeneradorDeFolio
{
    public function siguiente(string $tenantId, string $prefijo = 'SOL'): string
    {
        $fila = DB::select(
            'SELECT ultimo_numero FROM contadores_folio WHERE tenant_id = ? FOR UPDATE',
            [$tenantId]
        );

        if ($fila === []) {
            DB::statement(
                'INSERT INTO contadores_folio (tenant_id, ultimo_numero) VALUES (?, 0)',
                [$tenantId]
            );

            $numero = 1;
        } else {
            $numero = ((int) $fila[0]->ultimo_numero) + 1;
        }

        DB::statement(
            'UPDATE contadores_folio SET ultimo_numero = ?, actualizado_en = now() WHERE tenant_id = ?',
            [$numero, $tenantId]
        );

        return $prefijo . '-' . str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Sanea un prefijo para que no rompa el formato del folio.
     */
    public function prefijoLimpio(string $prefijo): string
    {
        $prefijo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefijo) ?? '');

        if ($prefijo === '') {
            return 'SOL';
        }

        if (strlen($prefijo) > 8) {
            throw new RuntimeException('El prefijo de folio no puede superar 8 caracteres.');
        }

        return $prefijo;
    }
}