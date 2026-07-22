<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Consultation;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\User;
use App\Models\VentePharmacieArticle;
use App\Models\VentesPharmacie;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * PIN commun à tous les utilisateurs (haché automatiquement par le cast 'pin').
     */
    private const PIN = '1234';

    public function run(): void
    {
        $modesPaiement = ['espece', 'orange', 'wave', 'moov'];

        // 1. Établissements (aucune dépendance)
        $typesEtab = ['cabinet', 'pharmacie', 'cabinet_pharmacie'];
        $etablissements = collect(range(1, 10))->map(fn ($i) => Etablissement::create([
            'nom' => "Établissement $i",
            'type' => $typesEtab[($i - 1) % 3],
            'telephone' => '20'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'actif' => true,
            'adresse' => "Quartier $i, Bamako",
        ]));

        // 2. Utilisateurs (FK etablissement) — tous le même PIN
        $roles = ['admin', 'medecin', 'pharmacien', 'caissier'];
        $users = collect(range(1, 10))->map(fn ($i) => User::create([
            'name' => "Utilisateur $i",
            'etablissement_id' => $etablissements->random()->id,
            'telephone' => '76'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'role' => $roles[($i - 1) % 4],
            'avatar' => null,
            'pin' => self::PIN,
            'actif' => true,
        ]));

        // Pivot etablissement_user (10 lignes)
        $users->each(fn (User $u) => $u->etablissements()->attach($u->etablissement_id));

        // 3. Patients (FK etablissement)
        $patients = collect(range(1, 10))->map(fn ($i) => Patient::create([
            'etablissement_id' => $etablissements->random()->id,
            'nom' => fake()->name(),
            'telephone' => '65'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'adresse' => fake()->streetAddress(),
        ]));

        // 4. Médicaments (FK etablissement)
        $nomsMed = ['Paracétamol', 'Amoxicilline', 'Ibuprofène', 'Métronidazole', 'Oméprazole', 'Aspirine', 'Cotrimoxazole', 'Artéméther', 'Chloroquine', 'Vitamine C'];
        $medicaments = collect(range(1, 10))->map(fn ($i) => Medicament::create([
            'etablissement_id' => $etablissements->random()->id,
            'nom' => $nomsMed[$i - 1],
            'stock' => random_int(10, 200),
            'prix_unitaire' => random_int(100, 5000),
            'seuil_alerte' => 5,
            'date_expiration' => now()->addMonths(random_int(3, 24))->format('Y-m-d'),
            'actif' => true,
        ]));

        // 5. Consultations (FK etablissement, patient, medecin)
        $medecins = $users->where('role', 'medecin')->values();
        $medecins = $medecins->isNotEmpty() ? $medecins : $users;
        $motifs = ['Fièvre', 'Toux', 'Céphalées', 'Douleurs abdominales', 'Contrôle', 'Paludisme', 'Hypertension', 'Plaie', 'Grippe', 'Fatigue'];
        $consultations = collect(range(1, 10))->map(function ($i) use ($patients, $medecins, $motifs) {
            $patient = $patients->random();

            return Consultation::create([
                'etablissement_id' => $patient->etablissement_id,
                'patient_id' => $patient->id,
                'medecin_id' => $medecins->random()->id,
                'motif' => $motifs[$i - 1],
                'montant' => random_int(2000, 15000),
                'statut' => ['en_attente', 'payee'][random_int(0, 1)],
            ]);
        });

        // 6. Ventes pharmacie (FK etablissement, patient)
        $ventes = collect(range(1, 10))->map(function ($i) use ($patients, $modesPaiement) {
            $patient = $patients->random();

            return VentesPharmacie::create([
                'etablissement_id' => $patient->etablissement_id,
                'patient_id' => $patient->id,
                'montant_total' => random_int(1000, 50000),
                'mode_paiement' => $modesPaiement[array_rand($modesPaiement)],
                'statut_sync' => 'synchronise',
                'reference_locale' => (string) Str::uuid(),
            ]);
        });

        // 7. Articles de vente (FK vente_pharmacie, medicament)
        collect(range(1, 10))->each(function () use ($ventes, $medicaments) {
            $medicament = $medicaments->random();
            $quantite = random_int(1, 10);
            $prixUnitaire = $medicament->prix_unitaire;

            VentePharmacieArticle::create([
                'vente_pharmacie_id' => $ventes->random()->id,
                'medicament_id' => $medicament->id,
                'quantite' => $quantite,
                'prix_unitaire' => $prixUnitaire,
                'prix_total' => $quantite * $prixUnitaire,
            ]);
        });

        // 8. Paiements (source polymorphe : Consultation ou VentesPharmacie)
        collect(range(1, 10))->each(function ($i) use ($consultations, $ventes, $modesPaiement) {
            if ($i % 2 === 1) {
                $source = $consultations->random();
                $type = Consultation::class;
                $montant = $source->montant;
            } else {
                $source = $ventes->random();
                $type = VentesPharmacie::class;
                $montant = $source->montant_total;
            }

            Paiement::create([
                'etablissement_id' => $source->etablissement_id,
                'source_type' => $type,
                'source_id' => $source->id,
                'montant' => $montant,
                'mode_paiement' => $modesPaiement[array_rand($modesPaiement)],
                'reference_locale' => (string) Str::uuid(),
            ]);
        });

        // 9. Mouvements de stock (FK etablissement, medicament)
        $typesMvt = ['entree', 'sortie', 'ajustement'];
        collect(range(1, 10))->each(function ($i) use ($medicaments, $typesMvt) {
            $medicament = $medicaments->random();

            MouvementStock::create([
                'etablissement_id' => $medicament->etablissement_id,
                'medicament_id' => $medicament->id,
                'type' => $typesMvt[array_rand($typesMvt)],
                'quantite' => random_int(1, 50),
                'commentaire' => "Mouvement de stock #$i",
            ]);
        });

        // 10. Journal d'audit (FK etablissement, user)
        $actions = ['creation_vente', 'suppression_patient', 'modification_medicament', 'creation_consultation', 'paiement_enregistre'];
        $cibles = ['Patient', 'VentePharmacie', 'Consultation', 'Medicament'];
        collect(range(1, 10))->each(function () use ($users, $actions, $cibles) {
            $user = $users->random();

            JournalAudit::create([
                'etablissement_id' => $user->etablissement_id,
                'user_id' => $user->id,
                'action' => $actions[array_rand($actions)],
                'type_cible' => $cibles[array_rand($cibles)],
                'id_cible' => random_int(1, 10),
            ]);
        });
    }
}
