<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Medicament;
use App\Models\VentePharmacieArticle;
use App\Models\VentesPharmacie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VentePharmacieArticle>
 */
class VentePharmacieArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prixUnitaire = fake()->randomFloat(2, 100, 5000);
        $quantite = fake()->numberBetween(1, 10);

        return [
            'uuid' => fake()->uuid(),
            'vente_pharmacie_id' => VentesPharmacie::factory(),
            'medicament_id' => Medicament::factory(),
            'quantite' => $quantite,
            'prix_unitaire' => $prixUnitaire,
            'prix_total' => $prixUnitaire * $quantite,
        ];
    }
}
