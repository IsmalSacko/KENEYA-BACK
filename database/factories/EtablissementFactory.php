<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Etablissement>
 */
class EtablissementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'nom' => fake()->company(),
            'type' => fake()->randomElement(['cabinet', 'pharmacie', 'cabinet_pharmacie']),
            'telephone' => fake()->numerify('2########'),
            'valide_at' => now(),
            'actif' => true,
            'adresse' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
        ];
    }
}
