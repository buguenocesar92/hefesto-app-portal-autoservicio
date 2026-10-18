<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolUsuario;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'authentik_sub' => (string) Str::uuid(),
            'correo' => fake()->unique()->safeEmail(),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->numerify('+56 9 #### ####'),
            'rol' => RolUsuario::Cliente,
            'activo' => true,
            'ultimo_acceso' => now(),
        ];
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => RolUsuario::Administrador]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    /** Usuario que pertenece a una PyME concreta. */
    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }
}