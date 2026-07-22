<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalAudit>
 */
class JournalAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['creation_patient', 'creation_vente', 'suppression_patient']),
            'type_cible' => 'Patient',
            'id_cible' => null,
            'cible_uuid' => fake()->uuid(),
        ];
    }
}
