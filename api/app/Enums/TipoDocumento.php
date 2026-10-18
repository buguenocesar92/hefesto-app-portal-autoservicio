<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de documento que la PyME emite a su cliente.
 *
 * OJO: estos son documentos YA emitidos por la PyME y cargados al
 * portal. El portal NO emite documentos tributarios ni se conecta
 * todavia a ningun proveedor de facturacion. Ver docs/09, seccion
 * "Lo que NO hace este portal".
 */
enum TipoDocumento: string
{
    case Factura = 'factura';
    case Boleta = 'boleta';
    case OrdenTrabajo = 'orden_trabajo';
    case Guia = 'guia';
    case Contrato = 'contrato';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::Boleta => 'Boleta',
            self::OrdenTrabajo => 'Orden de trabajo',
            self::Guia => 'Guia de despacho',
            self::Contrato => 'Contrato',
            self::Otro => 'Otro documento',
        };
    }

    /** Documentos de tipo tributario. Solo informativo para la UI. */
    public function esTributario(): bool
    {
        return match ($this) {
            self::Factura, self::Boleta => true,
            default => false,
        };
    }
}