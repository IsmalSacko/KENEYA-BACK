<?php

declare(strict_types=1);

namespace App\Sante\Abonnements;

use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Sante\Alertes\AlertesPlateforme;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La vente d'un abonnement payé, enregistrée dans la boutique NGONI CAISSE
 * (Ngoni Caisse, POST {console}/ventes), exactement comme la Console Ngoni
 * le fait quand elle approuve une demande payée par Mobile Money : même
 * contenu, même référence (l'uuid de la demande, pour l'application
 * « ngoni-sante »). Ngoni Caisse ne l'enregistre qu'une fois par référence.
 *
 * Sert aux paiements approuvés sans la console : l'App Store.
 */
final class VenteNgoniCaisse
{
    /** Le code de Ngoni Santé dans la Console Ngoni. */
    public const APPLICATION = 'ngoni-sante';

    /** Après la réponse : ni l'application ni Apple n'attendent Ngoni Caisse. */
    public function enregistrerApresReponse(DemandeAbonnement $demande): void
    {
        dispatch(fn () => $this->enregistrer($demande))->afterResponse();
    }

    /**
     * Rend le numéro de la vente, ou null : rien à enregistrer (montant nul),
     * Ngoni Caisse pas branchée, ou refus (l'équipe est alors prévenue).
     */
    public function enregistrer(DemandeAbonnement $demande): ?string
    {
        if ($demande->montant <= 0) {
            return null;
        }
        $url = rtrim(config()->string('sante.caisse.url', ''), '/');
        $cle = config()->string('sante.caisse.cle', '');
        if ($url === '' || $cle === '') {
            Log::warning('Ngoni Caisse non branchée : vente d’abonnement à saisir à la main', ['demande' => $demande->uuid]);

            return null;
        }

        $donnees = $this->donnees($demande);
        try {
            $reponse = Http::withToken($cle)->acceptJson()->connectTimeout(4)->timeout(10)->post($url.'/ventes', $donnees);
            if ($reponse->failed()) {
                $message = $reponse->json('message');
                throw new \RuntimeException(is_string($message) ? $message : "erreur {$reponse->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning('Vente d’abonnement non enregistrée dans Ngoni Caisse', ['demande' => $demande->uuid, 'erreur' => $e->getMessage()]);
            $etablissement = Etablissement::find($demande->etablissement_id);
            if ($etablissement !== null) {
                app(AlertesPlateforme::class)->abonnementAppStore(
                    $etablissement,
                    'Vente à saisir dans Ngoni Caisse',
                    "{$donnees['libelle']} · {$demande->montant} {$demande->devise} : Ngoni Caisse a refusé la vente ({$e->getMessage()}).",
                );
            }

            return null;
        }

        $vente = $reponse->json('vente');
        $numero = is_array($vente) ? ($vente['numero_facture'] ?? $vente['numero'] ?? $vente['id'] ?? null) : null;
        Log::info('Vente d’abonnement enregistrée dans Ngoni Caisse', ['demande' => $demande->uuid, 'vente' => $numero]);

        return is_scalar($numero) ? (string) $numero : '';
    }

    /**
     * Ce que la console envoie pour une demande approuvée (Federation::enregistrerVente).
     *
     * @return array{reference: string, application: string, client: array{nom: string, telephone: string|null, adresse: string}, libelle: string, montant: int, moyen: string}
     */
    public function donnees(DemandeAbonnement $demande): array
    {
        $etablissement = Etablissement::find($demande->etablissement_id);
        $formule = Formule::where('code', $demande->formule)->value('nom');
        $duree = $demande->mois === 12 ? '1 an' : "{$demande->mois} mois";
        $telephone = $demande->demandeur->telephone ?? $etablissement?->getAttribute('telephone');

        return [
            'reference' => $demande->uuid,
            'application' => self::APPLICATION,
            'client' => [
                'nom' => $etablissement->nom ?? 'Client Ngoni Santé',
                'telephone' => is_string($telephone) && $telephone !== '' ? $telephone : null,
                'adresse' => 'Ngoni Santé',
            ],
            'libelle' => mb_substr('Abonnement Ngoni Santé · '.(is_string($formule) ? $formule : $demande->formule)." · {$duree}"
                .($demande->reference !== null && $demande->reference !== '' ? " · réf. {$demande->reference}" : ''), 0, 255),
            'montant' => $demande->montant,
            'moyen' => $demande->moyen,
        ];
    }
}
