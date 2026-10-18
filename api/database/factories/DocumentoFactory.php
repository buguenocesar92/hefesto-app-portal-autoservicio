<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Documento>
 */
class DocumentoFactory extends Factory
{
    protected $model = Documento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $numero = (string) fake()->unique()->numerify('#####');

        return [
            'tenant_id' => Tenant::factory(),
            'usuario_id' => null,
            'tipo' => fake()->randomElement(TipoDocumento::cases()),
            'numero' => $numero,
            'detalle' => fake()->sentence(4),
            'emitido_en' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'monto_bruto' => fake()->randomFloat(2, 10000, 900000),
            'moneda' => 'CLP',
            'ruta_archivo' => 'documentos/' . $numero . '.pdf',
            'nombre_archivo' => 'documento-' . $numero . '.pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => fake()->numberBetween(20000, 900000),
            'hash_sha256' => hash('sha256', Str::random(32)),
        ];
    }

    /** Documento dirigido a una persona concreta. */
    public function para(string $usuarioId): static
    {
        return $this->state(fn (): array => ['usuario_id' => $usuarioId]);
    }
}