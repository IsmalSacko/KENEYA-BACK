<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Fournisseur;
use App\Models\Lot;
use App\Models\Medicament;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Visite;
use App\Sante\Caisse\Caisse;
use App\Sante\Pharmacie\Pharmacie;
use App\Sante\Pharmacie\SignatureOrdonnances;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La pharmacie : produits et lots, grossistes, réceptions, inventaire,
 * alertes ; ordonnances signées et délivrance.
 *
 * @tag Pharmacie
 */
class PharmacieController extends Controller
{
    public function __construct(
        private readonly Pharmacie $pharmacie,
        private readonly SignatureOrdonnances $signature,
        private readonly Caisse $caisse,
    ) {}

    // ------------------------------------------------------------ produits

    public function produits(Request $request): JsonResponse
    {
        $requete = Medicament::where('etablissement_id', $this->etablissementId())->orderBy('nom');
        $q = $request->query('q');
        if (is_string($q) && $q !== '') {
            $requete->where(fn ($w) => $w->where('nom', 'like', "%{$q}%")->orWhere('dci', 'like', "%{$q}%")->orWhere('code_barres', $q));
        }
        $produits = $requete->get();
        foreach ($produits as $m) {
            $this->pharmacie->aligner($m);
        }

        return response()->json([
            'data' => Medicament::with(['lots' => fn ($l) => $l->where('quantite', '>', 0)->orderByRaw('peremption is null, peremption')])
                ->whereIn('id', $produits->pluck('id'))
                ->orderBy('nom')
                ->get()
                ->map(fn (Medicament $m): array => $this->produit($m)),
        ]);
    }

    public function creerProduit(Request $request): JsonResponse
    {
        $m = Medicament::create([
            ...$this->validerProduit($request, true),
            'etablissement_id' => $this->etablissementId(),
            'stock' => 0,
        ]);

        return response()->json(['produit' => $this->produit($m)], 201);
    }

    /** Changer le conditionnement garde le nombre de boîtes en stock. */
    public function modifierProduit(Request $request, string $uuid): JsonResponse
    {
        $m = $this->medicament($uuid);
        $donnees = $this->validerProduit($request, false);

        DB::transaction(function () use ($m, $donnees): void {
            $ancien = max(1, $m->conditionnement);
            $saisi = $donnees['conditionnement'] ?? null;
            $nouveau = is_numeric($saisi) ? (int) $saisi : $ancien;
            if ($nouveau !== $ancien) {
                $this->pharmacie->aligner($m);
                $convertir = fn (int $u): int => intdiv($u * $nouveau, $ancien);
                foreach (Lot::where('medicament_id', $m->id)->get() as $lot) {
                    $lot->update(['quantite' => $convertir($lot->quantite), 'quantite_recue' => $convertir($lot->quantite_recue)]);
                }
                $donnees['stock'] = $convertir($m->stock);
            }
            $m->update($donnees);
        });

        return response()->json(['produit' => $this->produit($m->fresh() ?? $m)]);
    }

    // ------------------------------------------------------------ stock

    public function fournisseurs(): JsonResponse
    {
        return response()->json(['data' => Fournisseur::where('etablissement_id', $this->etablissementId())->orderBy('nom')->get()]);
    }

    public function creerFournisseur(Request $request): JsonResponse
    {
        $donnees = $request->validate(['nom' => 'required|string|max:120', 'telephone' => 'sometimes|nullable|string|max:20']);

        return response()->json(['fournisseur' => Fournisseur::create([...$donnees, 'etablissement_id' => $this->etablissementId()])], 201);
    }

    /** Une livraison : un lot par produit ; quantités en boîtes. */
    public function recevoir(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fournisseur_uuid' => 'sometimes|nullable|uuid',
            'lignes' => 'required|array|min:1',
            'lignes.*.medicament_uuid' => 'required|uuid',
            'lignes.*.numero' => 'sometimes|nullable|string|max:40',
            'lignes.*.peremption' => 'sometimes|nullable|date',
            'lignes.*.boites' => 'required|integer|min:1',
            'lignes.*.prix_achat' => 'sometimes|nullable|numeric|min:0',
        ]);
        $fournisseur = isset($donnees['fournisseur_uuid'])
            ? Fournisseur::where('etablissement_id', $this->etablissementId())->where('uuid', $donnees['fournisseur_uuid'])->firstOrFail()
            : null;
        $lignes = array_map(function (array $l): array {
            $m = $this->medicament($l['medicament_uuid']);

            return [
                'medicament' => $m,
                'numero' => $l['numero'] ?? null,
                'peremption' => $l['peremption'] ?? null,
                'quantite' => (int) $l['boites'] * max(1, $m->conditionnement),
                'prix_achat' => isset($l['prix_achat']) ? (float) $l['prix_achat'] : null,
            ];
        }, array_values($donnees['lignes']));

        $lots = $this->pharmacie->recevoir(Etablissement::findOrFail($this->etablissementId()), $fournisseur, $lignes);

        return response()->json(['lots' => $lots, 'message' => count($lots).' lot(s) reçu(s).'], 201);
    }

    public function inventorier(Request $request, string $uuid): JsonResponse
    {
        $comptee = (int) $request->validate(['quantite' => 'required|integer|min:0'])['quantite'];
        $lot = Lot::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();

        return response()->json(['lot' => $this->pharmacie->inventorier($lot, $comptee)]);
    }

    public function alertes(): JsonResponse
    {
        return response()->json($this->pharmacie->alertes(Etablissement::findOrFail($this->etablissementId())));
    }

    // ------------------------------------------------------------ ordonnances

    /** Le médecin prescrit : l'ordonnance est signée, son QR se vérifie hors ligne. */
    public function prescrire(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'visite_uuid' => 'sometimes|nullable|uuid',
            'patient_nom' => 'required_without:visite_uuid|nullable|string|max:120',
            'lignes' => 'required|array|min:1|max:20',
            'lignes.*.produit' => 'required|string|max:160',
            'lignes.*.medicament_uuid' => 'sometimes|nullable|uuid',
            'lignes.*.posologie' => 'sometimes|nullable|string|max:160',
            'lignes.*.quantite' => 'required|integer|min:1|max:10000',
        ]);
        $visite = isset($donnees['visite_uuid'])
            ? Visite::where('etablissement_id', $this->etablissementId())->where('uuid', $donnees['visite_uuid'])->firstOrFail()
            : null;

        $ordonnance = DB::transaction(function () use ($donnees, $visite): Ordonnance {
            $o = Ordonnance::create([
                'etablissement_id' => $this->etablissementId(),
                'prescripteur_id' => $this->currentUser()->id,
                'personne_id' => $visite?->personne_id,
                'visite_id' => $visite?->id,
                'patient_nom' => $donnees['patient_nom'] ?? ($visite !== null ? $visite->nom : null) ?? 'Patient',
                'statut' => 'emise',
                'signature' => '',
            ]);
            foreach ($donnees['lignes'] as $l) {
                $o->lignes()->create([
                    'produit' => $l['produit'],
                    'medicament_id' => isset($l['medicament_uuid']) ? Medicament::where('uuid', $l['medicament_uuid'])->value('id') : null,
                    'posologie' => $l['posologie'] ?? null,
                    'quantite' => $l['quantite'],
                ]);
            }
            $o->update(['signature' => $this->signature->signer($o)]);

            return $o;
        });

        return response()->json(['ordonnance' => $this->ordonnance($ordonnance)], 201);
    }

    public function ordonnances(Request $request): JsonResponse
    {
        $requete = Ordonnance::with('lignes', 'etablissement', 'prescripteur')
            ->where('etablissement_id', $this->etablissementId())
            ->latest();
        $visite = $request->query('visite');
        if (is_string($visite)) {
            $requete->whereIn('visite_id', Visite::where('uuid', $visite)->pluck('id'));
        }

        return response()->json(['data' => $requete->limit(100)->get()->map(fn (Ordonnance $o): array => $this->ordonnance($o))]);
    }

    /**
     * La pharmacie scanne un QR d'ordonnance (de n'importe quel
     * établissement) : authentique ? que reste-t-il à délivrer ?
     */
    public function lireOrdonnance(Request $request): JsonResponse
    {
        $qr = $request->validate(['qr' => 'required|string|max:4000'])['qr'];
        $contenu = $this->signature->lire($qr);
        if ($contenu === null) {
            return response()->json(['message' => 'Ordonnance non authentique : signature invalide.', 'code' => 'signature_invalide'], 422);
        }
        $ordonnance = Ordonnance::with('lignes', 'etablissement', 'prescripteur')->where('uuid', $contenu['u'] ?? '')->first();
        if ($ordonnance === null) {
            return response()->json(['message' => 'Ordonnance inconnue.'], 404);
        }

        return response()->json(['authentique' => true, 'ordonnance' => $this->ordonnance($ordonnance)]);
    }

    /** Délivrance au comptoir, sur ordonnance ou non. */
    public function delivrer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'visite_uuid' => 'sometimes|nullable|uuid',
            'ordonnance_uuid' => 'sometimes|nullable|uuid',
            'lignes' => 'required|array|min:1',
            'lignes.*.medicament_uuid' => 'required|uuid',
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.unite' => ['sometimes', Rule::in(['boite', 'unite'])],
            'lignes.*.ordonnance_ligne_uuid' => 'sometimes|nullable|uuid',
        ]);
        $etablissement = Etablissement::findOrFail($this->etablissementId());
        $visite = isset($donnees['visite_uuid'])
            ? Visite::where('etablissement_id', $etablissement->id)->where('uuid', $donnees['visite_uuid'])->firstOrFail()
            : null;
        // L'ordonnance peut venir d'un autre établissement.
        $ordonnance = isset($donnees['ordonnance_uuid']) ? Ordonnance::where('uuid', $donnees['ordonnance_uuid'])->firstOrFail() : null;

        $lignes = array_map(fn (array $l): array => [
            'medicament' => $this->medicament($l['medicament_uuid']),
            'quantite' => (int) $l['quantite'],
            'unite' => $l['unite'] ?? 'boite',
            'ligne' => isset($l['ordonnance_ligne_uuid']) && $ordonnance !== null
                ? OrdonnanceLigne::where('ordonnance_id', $ordonnance->id)->where('uuid', $l['ordonnance_ligne_uuid'])->firstOrFail()
                : null,
        ], array_values($donnees['lignes']));

        try {
            $visite = $this->pharmacie->delivrer($etablissement, $visite, $ordonnance, $lignes, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'visite' => $this->caisse->pourAffichage($visite->fresh() ?? $visite),
            'ordonnance' => $ordonnance !== null ? $this->ordonnance($ordonnance->fresh() ?? $ordonnance) : null,
        ]);
    }

    /** Clé publique de vérification des ordonnances (l'app la garde hors ligne). */
    public function clePublique(): JsonResponse
    {
        return response()->json(['algorithme' => 'ed25519', 'cle' => $this->signature->clePublique()]);
    }

    /** Les ordonnances de la personne, avec leur QR. */
    public function mesOrdonnances(): JsonResponse
    {
        return response()->json([
            'data' => Ordonnance::with('lignes', 'etablissement', 'prescripteur')
                ->where('personne_id', $this->currentPersonne()->id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Ordonnance $o): array => $this->ordonnance($o)),
        ]);
    }

    // ------------------------------------------------------------ interne

    /**
     * @return array<string, mixed>
     */
    private function produit(Medicament $m): array
    {
        $m->loadMissing(['lots' => fn ($l) => $l->where('quantite', '>', 0)->orderByRaw('peremption is null, peremption')]);
        $boite = max(1, $m->conditionnement);

        return [
            'uuid' => $m->uuid,
            'nom' => $m->nom,
            'dci' => $m->dci,
            'forme' => $m->forme,
            'dosage' => $m->dosage,
            'conditionnement' => $boite,
            'vente_unite' => $m->vente_unite,
            'prix_boite' => $m->prix_unitaire,
            'prix_unite' => $m->prix_unite,
            'ordonnance_requise' => $m->ordonnance_requise,
            'code_barres' => $m->getAttribute('code_barres'),
            'stock_unites' => $m->stock,
            'stock_boites' => intdiv($m->stock, $boite),
            'seuil_alerte' => $m->seuil_alerte,
            'actif' => $m->actif,
            'lots' => $m->lots->map(fn (Lot $l): array => [
                'uuid' => $l->uuid,
                'numero' => $l->numero,
                'peremption' => $l->peremption?->toDateString(),
                'quantite' => $l->quantite,
                'perime' => $l->peremption !== null && $l->peremption->isPast() && ! $l->peremption->isToday(),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ordonnance(Ordonnance $o): array
    {
        $o->loadMissing('lignes', 'etablissement', 'prescripteur');

        return [
            'uuid' => $o->uuid,
            'statut' => $o->statut,
            'patient_nom' => $o->patient_nom,
            'etablissement' => $o->etablissement->nom,
            'prescripteur' => $o->prescripteur?->getAttribute('name'),
            'date' => $o->created_at?->toDateString(),
            'qr' => $this->signature->qr($o),
            'lignes' => $o->lignes->map(fn (OrdonnanceLigne $l): array => [
                'uuid' => $l->uuid,
                'produit' => $l->produit,
                'posologie' => $l->posologie,
                'quantite' => $l->quantite,
                'quantite_delivree' => $l->quantite_delivree,
                'reste' => max(0, $l->quantite - $l->quantite_delivree),
                'medicament_uuid' => $l->medicament_id !== null ? Medicament::whereKey($l->medicament_id)->value('uuid') : null,
            ])->values(),
        ];
    }

    private function etablissementId(): int
    {
        return (int) $this->currentUser()->etablissement_id;
    }

    private function medicament(string $uuid): Medicament
    {
        return Medicament::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validerProduit(Request $request, bool $creation): array
    {
        $requis = $creation ? 'required' : 'sometimes';

        /** @var array<string, mixed> $donnees */
        $donnees = $request->validate([
            'nom' => "{$requis}|string|max:160",
            'dci' => 'sometimes|nullable|string|max:120',
            'forme' => 'sometimes|nullable|string|max:40',
            'dosage' => 'sometimes|nullable|string|max:40',
            'conditionnement' => 'sometimes|integer|min:1|max:10000',
            'vente_unite' => 'sometimes|boolean',
            'prix_unitaire' => "{$requis}|numeric|min:0",
            'prix_unite' => 'sometimes|nullable|numeric|min:0',
            'ordonnance_requise' => 'sometimes|boolean',
            'code_barres' => 'sometimes|nullable|string|max:40',
            'seuil_alerte' => 'sometimes|integer|min:0',
            'actif' => 'sometimes|boolean',
        ]);

        return $donnees;
    }
}
