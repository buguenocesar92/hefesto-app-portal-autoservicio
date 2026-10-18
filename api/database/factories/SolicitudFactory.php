<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoSolicitud;
use App\Enums\PrioridadSolicitud;
use App\Models\Solicitud;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Solicitud>
 */
class SolicitudFactory extends Factory
{
    protected $model = Solicitud::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $estado = fake()->randomElement(EstadoSolicitud::cases());

        return [
            'tenant_id' => fn (array $atributos): string => Usuario::query()
                ->whereKey($atributos['usuario_id'])
                ->value('tenant_id'),
            'folio' => 'SOL-' . fake()->unique()->numerify('#####'),
            'usuario_id' => Usuario::factory(),
            'categoria_id' => null,
            'titulo' => fake()->sentence(4),
            'descripcion' => fake()->paragraph(),
            'estado' => $estado,
            'prioridad' => fake()->randomElement(PrioridadSolicitud::cases()),
            'datos' => null,
            'asignado_a' => null,
            'cerrada_en' => $estado->esTerminal() ? now() : null,
        ];
    }

    public function en(EstadoSolicitud $estado): static
    {
        return $this->state(fn (): array => [
            'estado' => $estado,
            'cerrada_en' => $estado->esTerminal() ? now() : null,
        ]);
    }
}