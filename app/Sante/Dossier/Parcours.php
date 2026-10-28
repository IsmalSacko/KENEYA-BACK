<?php

declare(strict_types=1);

namespace App\Sante\Dossier;

use App\Models\DemandeExamen;
use App\Models\Etablissement;
use App\Models\ExamenClinique;
use App\Models\Hospitalisation;
use App\Models\Ordonnance;
use App\Models\Visite;
use App\Sante\Module;

/**
 * Le chemin du patient pendant une visite, étape par étape : arrivé,
 * consultation, labo, pharmacie, hospitalisation, caisse. Chacun voit où il
 * en est et ce qu'il reste à faire.
 *
 * Une étape n'apparaît que si l'établissement la pratique (son module) et,
 * pour le labo, la pharmacie et l'hospitalisation, que si la visite y passe.
 */
final class Parcours
{
    /**
     * @return list<array{etape: string, libelle: string, etat: string}>
     */
    public function de(Visite $visite, Etablissement $etab): array
    {
        $modules = $etab->modules;
        $a = static fn (Module $m): bool => in_array($m->value, $modules, true);

        $etapes = [['etape' => 'arrivee', 'libelle' => 'Arrivé', 'etat' => 'fait']];

        if ($a(Module::Consultation)) {
            $etapes[] = [
                'etape' => 'consultation',
                'libelle' => 'Consultation',
                'etat' => ExamenClinique::where('visite_id', $visite->id)->exists() ? 'fait' : 'a_faire',
            ];
        }

        $analyses = DemandeExamen::where('visite_id', $visite->id)->where('statut', '!=', 'annulee')->pluck('statut');
        if ($analyses->isNotEmpty()) {
            $etapes[] = [
                'etape' => 'labo',
                'libelle' => 'Labo',
                'etat' => $analyses->every(static fn (mixed $s): bool => $s === 'validee') ? 'fait' : 'a_faire',
            ];
        }

        $ordonnances = Ordonnance::where('visite_id', $visite->id)->where('statut', '!=', 'annulee')->pluck('statut');
        if ($ordonnances->isNotEmpty()) {
            $etapes[] = [
                'etape' => 'pharmacie',
                'libelle' => 'Pharmacie',
                'etat' => $ordonnances->every(static fn (mixed $s): bool => $s === 'delivree') ? 'fait' : 'a_faire',
            ];
        }

        $sejour = Hospitalisation::where('visite_id', $visite->id)->latest('entree_at')->first();
        if ($sejour !== null) {
            $etapes[] = [
                'etape' => 'hospitalisation',
                'libelle' => 'Hospitalisé',
                'etat' => $sejour->statut === 'sortie' ? 'fait' : 'a_faire',
            ];
        }

        if ($a(Module::Caisse)) {
            $etapes[] = [
                'etape' => 'caisse',
                'libelle' => 'Caisse',
                'etat' => $visite->total > 0 && $visite->reste() === 0 ? 'fait' : 'a_faire',
            ];
        }

        return $etapes;
    }
}
