<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'nom' => fake()->name(),
            'telephone' => fake()->numerify('7#######'),
            'adresse' => fake()->address(),
        ];
    }
}
