<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\Etablissement;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'patient_id' => Patient::factory(),
            'medecin_id' => User::factory(),
            'motif' => fake()->sentence(),
            'montant' => fake()->randomFloat(2, 1000, 50000),
            'statut' => 'en_attente',
        ];
    }
}
