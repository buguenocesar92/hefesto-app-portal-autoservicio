<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Da de alta una PyME en el portal.
 *
 * Este comando existe porque la tabla `tenants` es la RAIZ del aislamiento y
 * se consulta antes de que exista sesion (no lleva RLS a proposito). No hay
 * interfaz web para crearla: la crea quien administra la instalacion, que es
 * Hefesto. Ver docs/03, seccion "El punto ciego de tenants".
 *
 *   php artisan portal:crear-tenant panaderia-las-tortas \
 *       --nombre="Panaderia Las Tortas" \
 *       --dominio=lastortas.cl
 *
 * El `slug` es el identificador que aparece en la URL de entrada del portal.
 * El `dominio` es opcional, pero si se declara es la via por la que los
 * empleados de esa PyME entran sin escribir el slug: si el dominio no
 * corresponde al tenant de la URL, el acceso se rechaza.
 */
class CrearTenantCommand extends Command
{
    protected $signature = 'portal:crear-tenant
        {slug : Identificador corto de la PyME, sin acentos (ejemplo: panaderia-las-tortas)}
        {--nombre= : Nombre comercial que vera el cliente}
        {--razon-social= : Razon social}
        {--rut= : RUT de la PyME}
        {--dominio= : Dominio de correo de los empleados (ejemplo: lastortas.cl)}
        {--correo-contacto= : Correo de la PyME}
        {--telefono-contacto= : Telefono de la PyME}
        {--inactivo : Crear la PyME desactivada (no podra entrar al portal)}';

    protected $description = 'Da de alta una PyME cliente en el portal.';

    public function handle(): int
    {
        $slug = Tenant::normalizarSlug((string) $this->argument('slug'));

        if ($slug === '') {
            $this->error('El slug no quedo valido: usa solo letras, numeros y guiones.');

            return self::FAILURE;
        }

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error('Ya existe una PyME con el slug "' . $slug . '".');

            return self::FAILURE;
        }

        $dominio = Tenant::normalizarDominio($this->option('dominio'));

        if ($dominio !== null && Tenant::where('dominio_correo', $dominio)->exists()) {
            $this->error('El dominio "' . $dominio . '" ya lo usa otra PyME.');

            return self::FAILURE;
        }

        $tenant = Tenant::create([
            'slug' => $slug,
            'nombre' => (string) ($this->option('nombre') ?: $slug),
            'razon_social' => $this->option('razon-social'),
            'rut' => $this->option('rut'),
            'dominio_correo' => $dominio,
            'correo_contacto' => $this->option('correo-contacto'),
            'telefono_contacto' => $this->option('telefono-contacto'),
            'activo' => ! $this->option('inactivo'),
        ]);

        $this->info('PyME creada.');
        $this->line('  slug:    ' . $tenant->slug);
        $this->line('  nombre:  ' . $tenant->nombre);
        $this->line('  dominio: ' . ($tenant->dominio_correo ?? '(sin dominio declarado)'));
        $this->line('  activa:  ' . ($tenant->activo ? 'si' : 'no'));

        if ($dominio === null) {
            $this->newLine();
            $this->warn('Sin dominio declarado, el acceso solo funciona entrando por el slug.');
            $this->line('El primer ingreso tendra que hacerse por: /entrar?slug=' . $tenant->slug);
        }

        return self::SUCCESS;
    }
}