<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\RendezVous;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RendezVous>
 */
class RendezVousFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'medecin_id' => null,
            'patient_nom' => fake()->name(),
            'patient_telephone' => fake()->numerify('7#######'),
            'date_souhaitee' => fake()->dateTimeBetween('now', '+2 weeks'),
            'motif' => fake()->optional()->sentence(),
            'statut' => 'demande',
        ];
    }
}
