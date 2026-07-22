<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'etablissement_id' => Etablissement::factory(),
            'name' => fake()->name(),
            'telephone' => fake()->unique()->numerify('7#######'),
            'role' => 'admin',
            'avatar' => null,
            'pin' => '1234', // hashé par le cast 'pin' => 'hashed'
            'actif' => true,
        ];
    }
}
