<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\Etablissement;
use App\Models\Paiement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paiement>
 */
class PaiementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'source_type' => 'consultation',
            'source_id' => Consultation::factory(),
            'source_uuid' => fake()->uuid(),
            'montant' => fake()->randomFloat(2, 1000, 50000),
            'mode_paiement' => fake()->randomElement(['espece', 'orange', 'wave', 'moov']),
        ];
    }
}
