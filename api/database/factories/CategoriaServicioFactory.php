<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CategoriaServicio;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaServicio>
 */
class CategoriaServicioFactory extends Factory
{
    protected $model = CategoriaServicio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nombre' => fake()->unique()->randomElement([
                'Mantencion preventiva',
                'Reparacion de equipos',
                'Instalacion en sitio',
                'Soporte mensual',
                'Revision tecnica',
            ]),
            'descripcion' => fake()->sentence(),
            'campos' => null,
            'tiempo_respuesta_sugerido' => fake()->numberBetween(24, 120),
            'activa' => true,
        ];
    }

    /**
     * Categoria que pide datos extras, para probar la validacion dinamica.
     */
    public function conCampos(): static
    {
        return $this->state(fn (): array => [
            'campos' => [
                [
                    'clave' => 'numero_serie',
                    'etiqueta' => 'Numero de serie',
                    'tipo' => 'texto',
                    'requerido' => true,
                ],
                [
                    'clave' => 'franja_horaria',
                    'etiqueta' => 'Franja horaria preferida',
                    'tipo' => 'seleccion',
                    'requerido' => false,
                    'opciones' => ['manana', 'tarde', 'hora punta'],
                ],
            ],
        ]);
    }
}