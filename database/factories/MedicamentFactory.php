<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\Medicament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicament>
 */
class MedicamentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'nom' => fake()->word(),
            'stock' => fake()->numberBetween(0, 100),
            'prix_unitaire' => fake()->randomFloat(2, 100, 10000),
            'seuil_alerte' => 5,
            'date_expiration' => fake()->optional()->date(),
            'actif' => true,
        ];
    }
}
