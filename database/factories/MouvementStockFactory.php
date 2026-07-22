<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\Medicament;
use App\Models\MouvementStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouvementStock>
 */
class MouvementStockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'medicament_id' => Medicament::factory(),
            'type' => fake()->randomElement(['entree', 'sortie', 'ajustement']),
            'quantite' => fake()->numberBetween(1, 50),
            'commentaire' => fake()->optional()->sentence(),
        ];
    }
}
