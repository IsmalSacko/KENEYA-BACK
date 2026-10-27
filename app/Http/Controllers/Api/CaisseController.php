<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\Service;
use App\Models\Tarif;
use App\Models\Visite;
use App\Models\VisiteLigne;
use App\Sante\Caisse\Caisse;
use App\Sante\Caisse\TarifsParDefaut;
use App\Sante\Dossier\Dossiers;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La caisse unifiée : tarifs, visites et leur facture, encaissements,
 * sessions de caisse, rapport du jour.
 *
 * @tag Caisse
 */
class CaisseController extends Controller
{
    public function __construct(private readonly Caisse $caisse, private readonly Dossiers $dossiers) {}

    // ------------------------------------------------------------ tarifs

    public function tarifs(): JsonResponse
    {
        TarifsParDefaut::creerSiAucun(Etablissement::findOrFail($this->etablissementId()));

        return response()->json([
            'data' => Tarif::where('etablissement_id', $this->etablissementId())->orderBy('categorie')->orderBy('nom')->get(),
        ]);
    }

    public function creerTarif(Request $request): JsonResponse
    {
        $tarif = Tarif::create([...$this->validerTarif($request, true), 'etablissement_id' => $this->etablissementId()]);
        // Le premier tarif de consultation devient le ticket du service Consultation.
        if ($tarif->categorie === 'consultation') {
            Service::where('etablissement_id', $tarif->etablissement_id)->whereNull('tarif_id')
                ->where('nom', 'like', 'Consultation%')->update(['tarif_id' => $tarif->id]);
        }

        return response()->json(['tarif' => $tarif], 201);
    }

    public function modifierTarif(Request $request, string $uuid): JsonResponse
    {
        $tarif = $this->tarif($uuid);
        $tarif->update($this->validerTarif($request, false));

        return response()->json(['tarif' => $tarif->fresh()]);
    }

    public function supprimerTarif(string $uuid): JsonResponse
    {
        $this->tarif($uuid)->delete();

        return response()->json(['message' => 'Tarif supprimé.']);
    }

    // ------------------------------------------------------------ visites

    /** Visites ouvertes (ou du jour), les plus récentes d'abord. */
    /**
     * Une vente ou une facture sans ticket : le client du comptoir, quelqu'un
     * qui vient payer un acte. Nom et téléphone facultatifs.
     */
    public function creerVisite(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => 'sometimes|nullable|string|max:120',
            'telephone' => 'sometimes|nullable|string|max:30',
        ]);
        $etablissement = Etablissement::findOrFail($this->etablissementId());
        $nom = trim((string) ($donnees['nom'] ?? ''));
        $visite = $this->caisse->visitePourTicket($etablissement, [
            'nom' => $nom !== '' ? $nom : 'Client au comptoir',
            'telephone' => $donnees['telephone'] ?? null,
        ]);

        return response()->json(['visite' => $this->caisse->pourAffichage($visite)], 201);
    }

    public function visites(Request $request): JsonResponse
    {
        $requete = Visite::with('lignes', 'encaissements', 'tickets.service')
            ->where('etablissement_id', $this->etablissementId())
            ->where('ouverte_at', '>=', now()->subDay());
        if ($request->query('statut') !== 'toutes') {
            $requete->where('statut', 'ouverte');
        }
        $q = $request->query('q');
        if (is_string($q) && $q !== '') {
            $requete->where(fn ($w) => $w->where('nom', 'like', "%{$q}%")
                ->orWhere('telephone', 'like', "%{$q}%")
                ->orWhereHas('tickets', fn ($t) => $t->where('code', $q)));
        }

        return response()->json([
            'data' => $requete->latest('ouverte_at')->limit(100)->get()->map(fn (Visite $v): array => $this->caisse->pourAffichage($v)),
        ]);
    }

    public function visite(string $uuid): JsonResponse
    {
        $visite = $this->laVisite($uuid);
        // Les constantes du tri : pour les soignants, pas pour la caisse.
        $soignant = collect(['dossier.lire', 'dossier.ecrire', 'consultation.faire'])->contains(fn (string $d): bool => $this->currentUser()->peut($d));

        return response()->json(['visite' => [
            ...$this->caisse->pourAffichage($visite),
            'tri' => $soignant ? $this->dossiers->tri($visite) : null,
        ]]);
    }

    public function ajouterLigne(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'tarif_uuid' => 'sometimes|nullable|uuid',
            'libelle' => 'required_without:tarif_uuid|nullable|string|max:120',
            'prix' => 'required_without:tarif_uuid|nullable|integer|min:0',
            'quantite' => 'sometimes|integer|min:1|max:999',
        ]);
        $visite = $this->laVisite($uuid);
        $tarif = isset($donnees['tarif_uuid']) ? $this->tarif($donnees['tarif_uuid']) : null;

        return $this->faire(function () use ($visite, $tarif, $donnees): Visite {
            $this->caisse->ajouterLigne(
                $visite,
                $tarif,
                $donnees['libelle'] ?? null,
                isset($donnees['prix']) ? (int) $donnees['prix'] : null,
                (int) ($donnees['quantite'] ?? 1),
                $this->currentUser(),
            );

            return $visite;
        });
    }

    public function retirerLigne(string $uuid, string $ligne): JsonResponse
    {
        $visite = $this->laVisite($uuid);
        $l = VisiteLigne::where('visite_id', $visite->id)->where('uuid', $ligne)->firstOrFail();

        return $this->faire(function () use ($visite, $l): Visite {
            $this->caisse->retirerLigne($visite, $l);

            return $visite;
        });
    }

    public function encaisser(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'montant' => 'required|integer|min:1',
            'moyen' => ['required', 'string', Rule::in($this->moyens())],
            'reference' => 'sometimes|nullable|string|max:60',
        ]);
        $visite = $this->laVisite($uuid);

        return $this->faire(function () use ($visite, $donnees): Visite {
            $this->caisse->encaisser($visite, (int) $donnees['montant'], $donnees['moyen'], $donnees['reference'] ?? null, $this->currentUser());

            return $visite;
        });
    }

    public function cloturerVisite(string $uuid): JsonResponse
    {
        return response()->json(['visite' => $this->caisse->pourAffichage($this->caisse->cloturerVisite($this->laVisite($uuid)))]);
    }

    // ------------------------------------------------------------ sessions

    public function session(): JsonResponse
    {
        $session = $this->caisse->sessionOuverte($this->currentUser());

        return response()->json([
            'session' => $session === null ? null : [
                'uuid' => $session->uuid,
                'ouverte_at' => $session->ouverte_at->toIso8601String(),
                'fond_initial' => $session->fond_initial,
                'totaux' => $this->caisse->totauxSession($session),
            ],
            'moyens' => $this->moyens(),
            'devise' => Etablissement::findOrFail($this->etablissementId())->devise,
        ]);
    }

    public function ouvrir(Request $request): JsonResponse
    {
        $fond = (int) ($request->validate(['fond_initial' => 'sometimes|integer|min:0'])['fond_initial'] ?? 0);
        $this->caisse->ouvrirSession($this->currentUser(), $fond);

        return $this->session();
    }

    public function cloturer(Request $request): JsonResponse
    {
        $comptees = (int) $request->validate(['especes_comptees' => 'required|integer|min:0'])['especes_comptees'];
        $session = $this->caisse->sessionOuverte($this->currentUser());
        if ($session === null) {
            return response()->json(['message' => 'Aucune caisse ouverte.'], 422);
        }
        $totaux = $this->caisse->totauxSession($session);
        $session = $this->caisse->cloturerSession($session, $comptees);

        return response()->json([
            'message' => 'Caisse clôturée.',
            'fond_initial' => $session->fond_initial,
            'totaux' => $totaux,
            'especes_attendues' => $session->fond_initial + $totaux['especes'],
            'especes_comptees' => $session->especes_comptees,
            'ecart' => $session->ecart,
        ]);
    }

    public function rapport(Request $request): JsonResponse
    {
        $etablissement = Etablissement::findOrFail($this->etablissementId());
        $jour = $request->query('jour');

        return response()->json($this->caisse->rapportJour(
            $etablissement,
            is_string($jour) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) === 1 ? $jour : now()->toDateString(),
        ));
    }

    // ------------------------------------------------------------ patient

    /** Ses visites (et reçus) des 90 derniers jours. */
    public function mesVisites(): JsonResponse
    {
        return response()->json([
            'data' => Visite::with('lignes', 'encaissements', 'tickets.service')
                ->where('personne_id', $this->currentPersonne()->id)
                ->where('ouverte_at', '>=', now()->subDays(90))
                ->latest('ouverte_at')
                ->get()
                ->map(fn (Visite $v): array => [
                    ...$this->caisse->pourAffichage($v),
                    'etablissement' => Etablissement::whereKey($v->etablissement_id)->value('nom'),
                ]),
        ]);
    }

    // ------------------------------------------------------------ interne

    /**
     * @param  callable(): Visite  $action
     */
    private function faire(callable $action): JsonResponse
    {
        try {
            $visite = $action();
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['visite' => $this->caisse->pourAffichage($visite->fresh() ?? $visite)]);
    }

    /**
     * Moyens de paiement du pays de l'établissement.
     *
     * @return list<string>
     */
    private function moyens(): array
    {
        return $this->caisse->moyensDuPays(Etablissement::findOrFail($this->etablissementId()));
    }

    private function etablissementId(): int
    {
        return (int) $this->currentUser()->etablissement_id;
    }

    private function laVisite(string $uuid): Visite
    {
        return Visite::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    private function tarif(string $uuid): Tarif
    {
        return Tarif::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validerTarif(Request $request, bool $creation): array
    {
        $requis = $creation ? 'required' : 'sometimes';

        /** @var array<string, mixed> $donnees */
        $donnees = $request->validate([
            'nom' => "{$requis}|string|max:120",
            'categorie' => ['sometimes', Rule::in(['consultation', 'labo', 'soin', 'acte', 'hospitalisation'])],
            'prix' => "{$requis}|integer|min:0",
            'actif' => 'sometimes|boolean',
        ]);

        return $donnees;
    }
}
