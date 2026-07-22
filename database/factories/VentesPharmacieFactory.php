<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\VentesPharmacie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VentesPharmacie>
 */
class VentesPharmacieFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'patient_id' => null,
            'montant_total' => fake()->randomFloat(2, 500, 20000),
            'mode_paiement' => fake()->randomElement(['espece', 'orange', 'wave', 'moov']),
        ];
    }
}
