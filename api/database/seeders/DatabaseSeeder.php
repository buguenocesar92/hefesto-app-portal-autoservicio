<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Carga de datos iniciales.
 *
 * Para desarrollo: `php artisan migrate --seed`.
 * En produccion NO se usa este seeder: la PyME se da de alta con
 * `php artisan portal:crear-tenant`. Ver docs/04.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantDemoSeeder::class,
        ]);
    }
}