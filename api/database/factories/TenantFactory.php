<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->unique()->company();

        return [
            'slug' => Tenant::normalizarSlug($nombre) . '-' . Str::lower(Str::random(4)),
            'nombre' => $nombre,
            'razon_social' => $nombre . ' SpA',
            'rut' => fake()->unique()->numerify('##.###.###-#'),
            'dominio_correo' => fake()->unique()->domainWord() . '.cl',
            'correo_contacto' => fake()->safeEmail(),
            'telefono_contacto' => fake()->numerify('+56 9 #### ####'),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}