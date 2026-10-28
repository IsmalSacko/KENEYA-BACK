<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Fournisseur;
use App\Models\Lot;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Pays;
use App\Models\Visite;
use App\Models\VisiteLigne;
use App\Sante\Caisse\Caisse;
use App\Sante\Pharmacie\Pharmacie;
use App\Sante\Pharmacie\Rayon;
use App\Sante\Pharmacie\SignatureOrdonnances;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        // Les plus vendus des 30 derniers jours : les raccourcis de l'écran « Vendre ».
        /** @var array<int, int> $ventes */
        $ventes = VisiteLigne::whereIn('medicament_id', $produits->pluck('id'))->where('created_at', '>=', now()->subDays(30))
            ->groupBy('medicament_id')->selectRaw('medicament_id, COUNT(*) as n')->pluck('n', 'medicament_id')
            ->map(fn (mixed $n): int => is_numeric($n) ? (int) $n : 0)->all();

        return response()->json([
            'data' => Medicament::with(['lots' => fn ($l) => $l->where('quantite', '>', 0)->orderByRaw('peremption is null, peremption')])
                ->whereIn('id', $produits->pluck('id'))
                ->orderBy('nom')
                ->get()
                ->map(fn (Medicament $m): array => $this->produit($m, $ventes[$m->id] ?? 0)),
        ]);
    }

    public function creerProduit(Request $request): JsonResponse
    {
        $donnees = $this->validerProduit($request, true);
        if ($this->nomPris($donnees['nom'] ?? null)) {
            return response()->json(['message' => 'Ce produit existe déjà : modifiez-le, ou faites une réception.'], 422);
        }
        $m = Medicament::create([
            ...$donnees,
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
        if ($this->nomPris($donnees['nom'] ?? null, $m->id)) {
            return response()->json(['message' => 'Un autre produit porte déjà ce nom.'], 422);
        }

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

    /** Retiré de la vente : son stock sort, l'historique garde son nom. */
    public function supprimerProduit(string $uuid): JsonResponse
    {
        $m = $this->medicament($uuid);
        $this->pharmacie->supprimerProduit($m);

        return response()->json(['message' => "{$m->nom} est supprimé."]);
    }

    /** Ce qui est entré et sorti : réceptions, ventes, inventaires, retraits. */
    public function mouvements(string $uuid): JsonResponse
    {
        $m = $this->medicament($uuid);
        $boite = max(1, $m->conditionnement);

        return response()->json(['data' => MouvementStock::where('medicament_id', $m->id)->latest('id')->limit(100)->get()
            ->map(fn (MouvementStock $x): array => [
                'type' => $x->type,
                // L'inventaire note son sens dans le commentaire : « (+5) » ou « (-3) ».
                'entree' => $x->type === 'entree' || ($x->type === 'ajustement' && str_contains((string) $x->commentaire, '(+')),
                'quantite' => $x->quantite,
                'boites' => intdiv((int) $x->quantite, $boite),
                'commentaire' => $x->commentaire,
                'date' => $x->getRawOriginal('created_at'),
            ])]);
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

    public function modifierFournisseur(Request $request, string $uuid): JsonResponse
    {
        $f = $this->fournisseur($uuid);
        $f->update($request->validate(['nom' => 'sometimes|string|max:120', 'telephone' => 'sometimes|nullable|string|max:20']));

        return response()->json(['fournisseur' => $f->fresh()]);
    }

    /** Les lots déjà reçus de lui gardent leur historique. */
    public function supprimerFournisseur(string $uuid): JsonResponse
    {
        $f = $this->fournisseur($uuid);
        $f->delete();

        return response()->json(['message' => "{$f->nom} est supprimé."]);
    }

    /** Corriger un lot mal saisi : numéro, péremption. */
    public function modifierLot(Request $request, string $uuid): JsonResponse
    {
        $lot = $this->lot($uuid);
        $lot->update($request->validate([
            'numero' => 'sometimes|nullable|string|max:40',
            'peremption' => 'sometimes|nullable|date',
        ]));

        return response()->json(['lot' => $lot->fresh()]);
    }

    /** Périmé, abîmé, perdu : le reste du lot sort du stock. */
    public function retirerLot(Request $request, string $uuid): JsonResponse
    {
        $raison = $request->validate(['raison' => 'sometimes|nullable|string|max:80'])['raison'] ?? null;
        $lot = $this->pharmacie->retirerLot($this->lot($uuid), is_string($raison) && $raison !== '' ? $raison : 'Retiré du stock');

        return response()->json(['lot' => $lot]);
    }

    /** Réception saisie par erreur, tant que rien n'en est sorti. */
    public function annulerReception(string $uuid): JsonResponse
    {
        $this->pharmacie->annulerReception($this->lot($uuid));

        return response()->json(['message' => 'Réception annulée.']);
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

    /** Les patients qui attendent la pharmacie et leurs ordonnances du jour. */
    public function aServir(): JsonResponse
    {
        $etab = Etablissement::findOrFail($this->etablissementId());
        $fuseau = Pays::whereKey($etab->pays_code)->value('fuseau');
        $depuis = now()->setTimezone(is_string($fuseau) ? $fuseau : 'Africa/Bamako')->startOfDay()->utc();

        return response()->json(['data' => array_map(fn (array $a): array => [
            'patient' => $a['patient'],
            'visite_uuid' => $a['visite_id'] !== null ? Visite::whereKey($a['visite_id'])->value('uuid') : null,
            'ticket' => $a['ticket'] === null ? null : [
                'uuid' => $a['ticket']->uuid,
                'code' => $a['ticket']->code,
                'statut' => $a['ticket']->statut->value,
            ],
            'ordonnances' => array_map(fn (Ordonnance $o): array => $this->ordonnance($o), $a['ordonnances']),
        ], $this->pharmacie->aServir($etab, $depuis))]);
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

    /**
     * La vente d'une officine : le panier, l'ordonnance scannée s'il y en a
     * une, le client (facultatif) et le paiement. Sans paiement, ou avec la
     * caisse séparée, la vente attend le caissier.
     */
    public function vendre(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'ordonnance_uuid' => 'sometimes|nullable|uuid',
            'lignes' => 'required|array|min:1',
            'lignes.*.medicament_uuid' => 'required|uuid',
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.unite' => ['sometimes', Rule::in(['boite', 'unite'])],
            'lignes.*.ordonnance_ligne_uuid' => 'sometimes|nullable|uuid',
            'client.nom' => 'sometimes|nullable|string|max:120',
            'client.telephone' => 'sometimes|nullable|string|max:30',
            'paiement.moyen' => ['sometimes', 'string', Rule::in([...$this->caisse->moyensDuPays($this->etablissement()), 'credit'])],
            'paiement.reference' => 'sometimes|nullable|string|max:60',
        ]);
        $etablissement = $this->etablissement();
        $ordonnance = isset($donnees['ordonnance_uuid']) ? Ordonnance::where('uuid', $donnees['ordonnance_uuid'])->firstOrFail() : null;
        $lignes = $this->lignesADelivrer(array_values($donnees['lignes']), $ordonnance);
        $texte = fn (string $cle): ?string => is_string($v = $request->input($cle)) ? $v : null;
        $client = ['nom' => $texte('client.nom'), 'telephone' => $texte('client.telephone')];
        $moyen = $texte('paiement.moyen');
        $paiement = $moyen !== null ? ['moyen' => $moyen, 'reference' => $texte('paiement.reference')] : null;

        try {
            $vente = $this->pharmacie->vendre($etablissement, $ordonnance, $lignes, $client, $paiement, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['vente' => $this->pharmacie->vente($vente)], 201);
    }

    /** Les ventes d'un jour (aujourd'hui par défaut) et leurs totaux. */
    public function ventes(Request $request): JsonResponse
    {
        $jour = $request->query('jour');

        return response()->json($this->pharmacie->ventesDuJour(
            $this->etablissement(),
            is_string($jour) && $jour !== '' ? Carbon::parse($jour) : now(),
        ));
    }

    public function annulerVente(string $uuid): JsonResponse
    {
        $vente = Visite::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
        try {
            $vente = $this->pharmacie->annulerVente($vente, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['vente' => $this->pharmacie->vente($vente)]);
    }

    public function aCommander(): JsonResponse
    {
        return response()->json(['data' => $this->pharmacie->aCommander($this->etablissement())]);
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

        $lignes = $this->lignesADelivrer(array_values($donnees['lignes']), $ordonnance);

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
    private function produit(Medicament $m, int $ventes = 0): array
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
            'rayon' => $m->rayon,
            'prix_achat' => $m->prix_achat,
            'ventes' => $ventes,
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
                // Rien n'en est sorti : la réception peut encore s'annuler.
                'intact' => $l->quantite === $l->quantite_recue,
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

    /** Un produit de l'établissement porte-t-il déjà ce nom (casse ignorée) ? */
    private function nomPris(mixed $nom, ?int $sauf = null): bool
    {
        if (! is_string($nom) || trim($nom) === '') {
            return false;
        }

        return Medicament::where('etablissement_id', $this->etablissementId())
            ->whereRaw('lower(trim(nom)) = ?', [mb_strtolower(trim($nom))])
            ->when($sauf !== null, fn ($q) => $q->whereKeyNot($sauf))
            ->exists();
    }

    private function medicament(string $uuid): Medicament
    {
        return Medicament::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    private function lot(string $uuid): Lot
    {
        return Lot::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    private function fournisseur(string $uuid): Fournisseur
    {
        return Fournisseur::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Les lignes du panier, chacune reliée à la ligne de l'ordonnance où le
     * produit a été prescrit (précisée, ou retrouvée par le produit).
     *
     * @param  list<array<string, mixed>>  $saisies
     * @return list<array{medicament: Medicament, quantite: int, unite: string, ligne: OrdonnanceLigne|null}>
     */
    private function lignesADelivrer(array $saisies, ?Ordonnance $ordonnance): array
    {
        return array_map(function (array $l) use ($ordonnance): array {
            $uuid = $l['medicament_uuid'] ?? null;
            $medicament = $this->medicament(is_string($uuid) ? $uuid : '');
            $ligne = null;
            if ($ordonnance !== null) {
                $ligne = isset($l['ordonnance_ligne_uuid'])
                    ? OrdonnanceLigne::where('ordonnance_id', $ordonnance->id)->where('uuid', $l['ordonnance_ligne_uuid'])->firstOrFail()
                    : OrdonnanceLigne::where('ordonnance_id', $ordonnance->id)->where('medicament_id', $medicament->id)->first();
            }
            $unite = ($l['unite'] ?? 'boite') === 'unite' ? 'unite' : 'boite';

            return ['medicament' => $medicament, 'quantite' => is_numeric($l['quantite']) ? (int) $l['quantite'] : 0, 'unite' => $unite, 'ligne' => $ligne];
        }, $saisies);
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->etablissementId());
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
            'rayon' => ['sometimes', Rule::in(Rayon::values())],
            'prix_achat' => 'sometimes|nullable|numeric|min:0',
        ]);
        // « Sur ordonnance » ne concerne que les médicaments.
        if (isset($donnees['rayon']) && $donnees['rayon'] !== Rayon::Medicaments->value) {
            $donnees['ordonnance_requise'] = false;
        }

        return $donnees;
    }
}
