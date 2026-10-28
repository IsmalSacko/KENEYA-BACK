<?php

declare(strict_types=1);

namespace App\Sante\Pharmacie;

use App\Models\Etablissement;
use App\Models\Fournisseur;
use App\Models\Lot;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visite;
use App\Models\VisiteLigne;
use App\Sante\Caisse\Caisse;
use App\Sante\Devise;
use App\Sante\File\Statut;
use App\Sante\Refus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le stock par lots (on délivre d'abord ce qui périme le plus tôt, jamais un
 * lot périmé), les réceptions des grossistes, l'inventaire, et la
 * délivrance qui facture sur la visite et compte l'ordonnance.
 */
final class Pharmacie
{
    /** Péremption proche : moins de 90 jours. */
    public const PEREMPTION_PROCHE_JOURS = 90;

    public function __construct(private readonly Caisse $caisse) {}

    /**
     * Le total `stock` peut avoir bougé sans les lots (synchro de l'app 3.x) :
     * une sortie consomme les lots par péremption, une entrée devient un lot
     * sans numéro.
     */
    public function aligner(Medicament $medicament): void
    {
        // Toujours le stock en base : une copie en mémoire peut dater d'avant
        // la ligne précédente d'une même réception.
        $medicament->refresh();
        $lots = Lot::where('medicament_id', $medicament->id)->where('quantite', '>', 0)->orderByRaw('peremption is null, peremption')->get();
        $ecart = $medicament->stock - self::entier($lots->sum('quantite'));

        if ($ecart > 0) {
            Lot::create([
                'etablissement_id' => $medicament->etablissement_id,
                'medicament_id' => $medicament->id,
                'numero' => null,
                'peremption' => null,
                'quantite' => $ecart,
                'quantite_recue' => $ecart,
            ]);
        } elseif ($ecart < 0) {
            $aRetirer = -$ecart;
            foreach ($lots as $lot) {
                $pris = min($lot->quantite, $aRetirer);
                $lot->update(['quantite' => $lot->quantite - $pris]);
                $aRetirer -= $pris;
                if ($aRetirer === 0) {
                    break;
                }
            }
        }
    }

    /**
     * Retire des unités, lot par lot, en commençant par la péremption la plus
     * proche ; les lots périmés ne sont jamais délivrés.
     *
     * @return list<array{lot: string|null, peremption: string|null, quantite: int}>
     */
    public function sortir(Medicament $medicament, int $unites, string $commentaire): array
    {
        $this->aligner($medicament);
        $aujourdhui = now()->toDateString();
        $lots = Lot::where('medicament_id', $medicament->id)
            ->where('quantite', '>', 0)
            ->where(fn ($q) => $q->whereNull('peremption')->orWhere('peremption', '>=', $aujourdhui))
            ->orderByRaw('peremption is null, peremption')
            ->lockForUpdate()
            ->get();

        if (self::entier($lots->sum('quantite')) < $unites) {
            throw new Refus("Stock insuffisant pour {$medicament->nom} (hors lots périmés).");
        }

        $utilises = [];
        $reste = $unites;
        foreach ($lots as $lot) {
            $pris = min($lot->quantite, $reste);
            $lot->update(['quantite' => $lot->quantite - $pris]);
            $utilises[] = ['lot' => $lot->numero, 'peremption' => $lot->peremption?->toDateString(), 'quantite' => $pris];
            $reste -= $pris;
            if ($reste === 0) {
                break;
            }
        }

        $this->bouger($medicament, 'sortie', -$unites, $commentaire);

        return $utilises;
    }

    /**
     * Réception d'une livraison du grossiste : un lot par ligne.
     *
     * @param  list<array{medicament: Medicament, numero: string|null, peremption: string|null, quantite: int, prix_achat: float|null}>  $lignes
     * @return list<Lot>
     */
    public function recevoir(Etablissement $etablissement, ?Fournisseur $fournisseur, array $lignes): array
    {
        return DB::transaction(function () use ($etablissement, $fournisseur, $lignes): array {
            $lots = [];
            foreach ($lignes as $l) {
                $this->aligner($l['medicament']);
                $lots[] = Lot::create([
                    'etablissement_id' => $etablissement->id,
                    'medicament_id' => $l['medicament']->id,
                    'fournisseur_id' => $fournisseur?->id,
                    'numero' => $l['numero'],
                    'peremption' => $l['peremption'],
                    'quantite' => $l['quantite'],
                    'quantite_recue' => $l['quantite'],
                    'prix_achat' => $l['prix_achat'],
                ]);
                $this->bouger($l['medicament'], 'entree', $l['quantite'], 'Réception'.($fournisseur !== null ? ' '.$fournisseur->nom : ''));
            }

            return $lots;
        });
    }

    /** Inventaire d'un lot : la quantité comptée remplace la quantité connue. */
    public function inventorier(Lot $lot, int $comptee): Lot
    {
        return DB::transaction(function () use ($lot, $comptee): Lot {
            $medicament = Medicament::withTrashed()->findOrFail($lot->medicament_id);
            $this->aligner($medicament);
            $lot->refresh();
            $ecart = $comptee - $lot->quantite;
            if ($ecart !== 0) {
                $lot->update(['quantite' => $comptee]);
                $this->bouger($medicament, 'ajustement', $ecart, 'Inventaire du lot '.($lot->numero ?? 'sans numéro').' ('.($ecart > 0 ? '+' : '').$ecart.')');
            }

            return $lot;
        });
    }

    /**
     * Un produit retiré de la vente : son stock sort (noté dans les
     * mouvements), il disparaît des listes ; l'historique (ventes,
     * ordonnances) garde son nom.
     */
    public function supprimerProduit(Medicament $medicament): void
    {
        DB::transaction(function () use ($medicament): void {
            $this->aligner($medicament);
            if ($medicament->stock > 0) {
                $this->bouger($medicament, 'sortie', -$medicament->stock, 'Produit supprimé');
            }
            Lot::where('medicament_id', $medicament->id)->update(['quantite' => 0]);
            $medicament->delete();
        });
    }

    /**
     * Un lot sort du stock (périmé, abîmé, perdu) : ce qui reste est noté
     * comme une sortie, avec la raison.
     */
    public function retirerLot(Lot $lot, string $raison): Lot
    {
        return DB::transaction(function () use ($lot, $raison): Lot {
            $medicament = Medicament::withTrashed()->findOrFail($lot->medicament_id);
            $this->aligner($medicament);
            $lot->refresh();
            if ($lot->quantite > 0) {
                $reste = $lot->quantite;
                $lot->update(['quantite' => 0]);
                $this->bouger($medicament, 'sortie', -$reste, $raison.' : lot '.($lot->numero ?? 'sans numéro'));
            }

            return $lot;
        });
    }

    /**
     * Réception saisie par erreur : le lot disparaît tant que rien n'en est
     * sorti ; sinon on le compte (inventaire) ou on le retire.
     */
    public function annulerReception(Lot $lot): void
    {
        DB::transaction(function () use ($lot): void {
            $medicament = Medicament::withTrashed()->findOrFail($lot->medicament_id);
            $this->aligner($medicament);
            $lot->refresh();
            if ($lot->quantite !== $lot->quantite_recue) {
                throw new Refus('Des unités de ce lot sont déjà sorties : comptez-le (inventaire) ou retirez-le.');
            }
            $this->bouger($medicament, 'sortie', -$lot->quantite, 'Réception annulée : lot '.($lot->numero ?? 'sans numéro'));
            $lot->delete();
        });
    }

    /**
     * Délivrance au comptoir, avec ou sans ordonnance : sort le stock, facture
     * sur la visite, compte ce qui est délivré sur l'ordonnance.
     *
     * @param  list<array{medicament: Medicament, quantite: int, unite: string, ligne: OrdonnanceLigne|null}>  $lignes
     */
    public function delivrer(Etablissement $etablissement, ?Visite $visite, ?Ordonnance $ordonnance, array $lignes, User $par): Visite
    {
        return DB::transaction(function () use ($etablissement, $visite, $ordonnance, $lignes, $par): Visite {
            // L'ordonnance prescrite ici : on facture sur la visite du patient.
            $prescrite = $ordonnance?->visite_id !== null
                ? Visite::whereKey($ordonnance->visite_id)->where('etablissement_id', $etablissement->id)->first()
                : null;
            $ancienne = $visite ?? $prescrite;
            if ($visite?->statut !== 'ouverte') {
                $visite = $prescrite?->statut === 'ouverte' ? $prescrite : null;
            }
            // Visite déjà clôturée (le patient est passé à la caisse avant) :
            // une nouvelle facture, pour le même patient.
            $visite ??= $this->caisse->visitePourTicket($etablissement, [
                'nom' => $ancienne->nom ?? ($ordonnance !== null ? $ordonnance->patient_nom : 'Comptoir'),
                'telephone' => $ancienne?->telephone,
                'personne_id' => $ancienne->personne_id ?? $ordonnance?->personne_id,
                'dossier_id' => $ancienne?->dossier_id,
            ]);

            foreach ($lignes as $l) {
                $m = $l['medicament'];
                $parUnite = $l['unite'] === 'unite';
                if ($parUnite && ! $m->vente_unite) {
                    throw new Refus("{$m->nom} se vend à la boîte.");
                }
                if ($m->ordonnance_requise && $l['ligne'] === null) {
                    throw new Refus("{$m->nom} ne se délivre que sur ordonnance.");
                }
                $unites = $parUnite ? $l['quantite'] : $l['quantite'] * max(1, $m->conditionnement);

                if ($l['ligne'] !== null) {
                    $ligne = OrdonnanceLigne::whereKey($l['ligne']->id)->lockForUpdate()->firstOrFail();
                    if ($ligne->quantite_delivree + $unites > $ligne->quantite) {
                        $reste = max(0, $ligne->quantite - $ligne->quantite_delivree);
                        throw new Refus("Ordonnance : il ne reste que {$reste} unité(s) de « {$ligne->produit} » à délivrer.");
                    }
                    $ligne->update(['quantite_delivree' => $ligne->quantite_delivree + $unites, 'medicament_id' => $ligne->medicament_id ?? $m->id]);
                }

                $this->sortir($m, $unites, 'Délivrance');

                $prix = $parUnite
                    ? Devise::versMineur($m->prix_unite ?? 0, $etablissement->devise)
                    : Devise::versMineur($m->prix_unitaire, $etablissement->devise);
                $libelle = $m->nom.' · '.$l['quantite'].' '.($parUnite ? 'unité(s)' : 'boîte(s)');
                $this->caisse->ajouterLigne($visite, null, $libelle, $prix, $l['quantite'], $par)
                    ->update(['medicament_id' => $m->id, 'unites' => $unites]);
            }

            if ($ordonnance !== null) {
                $this->majStatut($ordonnance);
            }
            // Servi : le patient quitte la file de la pharmacie.
            Ticket::where('visite_id', $visite->id)
                ->whereIn('statut', [Statut::Emis->value, Statut::Appele->value, Statut::EnService->value])
                ->whereHas('service', fn ($q) => $q->where('nom', 'like', 'Pharmacie%'))
                ->update(['statut' => Statut::Termine->value, 'termine_at' => now()]);

            return $visite;
        });
    }

    public function majStatut(Ordonnance $ordonnance): void
    {
        $lignes = $ordonnance->lignes()->get();
        $tout = $lignes->every(fn (OrdonnanceLigne $l): bool => $l->quantite_delivree >= $l->quantite);
        $rien = $lignes->every(fn (OrdonnanceLigne $l): bool => $l->quantite_delivree === 0);
        $ordonnance->update(['statut' => $tout ? 'delivree' : ($rien ? 'emise' : 'partielle')]);
    }

    /**
     * Qui attend la pharmacie aujourd'hui : les patients dans sa file, et les
     * ordonnances prescrites ici pas encore servies — avec ou sans ticket.
     *
     * @return list<array{patient: string, visite_id: int|null, ticket: Ticket|null, ordonnances: list<Ordonnance>}>
     */
    public function aServir(Etablissement $etablissement, Carbon $depuis): array
    {
        $tickets = Ticket::with('service')->where('etablissement_id', $etablissement->id)
            ->where('emis_at', '>=', $depuis)
            ->whereIn('statut', [Statut::Emis->value, Statut::Appele->value, Statut::EnService->value])
            ->whereHas('service', fn ($q) => $q->where('nom', 'like', 'Pharmacie%'))
            ->orderBy('emis_at')->get();
        // Visite clôturée : le patient est reparti, il revient avec le QR.
        $ordonnances = Ordonnance::with('lignes')->where('etablissement_id', $etablissement->id)
            ->where('created_at', '>=', $depuis)->whereIn('statut', ['emise', 'partielle'])
            ->where(fn ($q) => $q->whereNull('visite_id')
                ->orWhereIn('visite_id', Visite::select('id')->where('statut', 'ouverte')))
            ->orderBy('created_at')->get();

        $parVisite = [];
        foreach ($tickets as $t) {
            $cle = $t->visite_id !== null ? 'v'.$t->visite_id : 't'.$t->id;
            $parVisite[$cle] = ['patient' => $t->nom ?? 'Patient', 'visite_id' => $t->visite_id, 'ticket' => $t, 'ordonnances' => []];
        }
        foreach ($ordonnances as $o) {
            $cle = $o->visite_id !== null ? 'v'.$o->visite_id : 'o'.$o->id;
            $parVisite[$cle] ??= ['patient' => $o->patient_nom, 'visite_id' => $o->visite_id, 'ticket' => null, 'ordonnances' => []];
            $parVisite[$cle]['ordonnances'][] = $o;
        }

        return array_values($parVisite);
    }

    /**
     * La vente au comptoir d'une officine : les produits sortent du stock sur
     * un ticket de vente, payé tout de suite si le vendeur encaisse lui-même ;
     * avec la caisse séparée, le client passe payer au caissier.
     *
     * @param  list<array{medicament: Medicament, quantite: int, unite: string, ligne: OrdonnanceLigne|null}>  $lignes
     * @param  array{nom?: string|null, telephone?: string|null}  $client
     * @param  array{moyen: string, reference?: string|null}|null  $paiement
     */
    public function vendre(Etablissement $etablissement, ?Ordonnance $ordonnance, array $lignes, array $client, ?array $paiement, User $vendeur): Visite
    {
        return DB::transaction(function () use ($etablissement, $ordonnance, $lignes, $client, $paiement, $vendeur): Visite {
            $nom = trim((string) ($client['nom'] ?? ''));
            $vente = $this->caisse->visitePourTicket($etablissement, [
                'nom' => $nom !== '' ? $nom : ($ordonnance !== null ? $ordonnance->patient_nom : 'Client au comptoir'),
                'telephone' => $client['telephone'] ?? null,
            ]);
            $vente->update(['nature' => 'vente']);
            $vente = $this->delivrer($etablissement, $vente, $ordonnance, $lignes, $vendeur);

            // À crédit : le client emporte et paiera plus tard (son nom est requis).
            if ($paiement !== null && $paiement['moyen'] === 'credit') {
                if (trim((string) $vente->nom) === '' || $vente->nom === 'Client au comptoir') {
                    throw new Refus('Une vente à crédit se fait au nom du client.');
                }
                $vente->update(['credit' => true]);

                return $vente->refresh();
            }
            $encaisseIci = $paiement !== null && ! $etablissement->caisse_separee && $vendeur->peut('caisse.encaisser');
            if ($encaisseIci) {
                // Le vendeur encaisse : sa caisse s'ouvre d'elle-même à la première vente.
                $this->caisse->ouvrirSession($vendeur, 0);
                $reste = $vente->refresh()->reste();
                if ($reste > 0) {
                    $this->caisse->encaisser($vente, $reste, $paiement['moyen'], $paiement['reference'] ?? null, $vendeur);
                } else {
                    $vente->update(['statut' => 'cloturee', 'cloturee_at' => now()]);
                }
            }

            return $vente->refresh();
        });
    }

    /**
     * Les ventes d'un jour : chacune, et les totaux (encaissé, à encaisser,
     * par moyen de paiement, par rayon).
     *
     * @return array<string, mixed>
     */
    public function ventesDuJour(Etablissement $etablissement, Carbon $jour): array
    {
        $debut = $jour->copy()->startOfDay();
        $fin = $jour->copy()->endOfDay();
        $toutes = Visite::with('lignes', 'encaissements')->where('etablissement_id', $etablissement->id)
            ->where('nature', 'vente')->whereBetween('ouverte_at', [$debut, $fin])->latest('ouverte_at')->get();
        $ventes = $toutes->where('statut', '!=', 'annulee');

        $parMoyen = [];
        foreach ($ventes->flatMap(fn (Visite $v) => $v->encaissements->whereNull('rembourse_at')) as $e) {
            $parMoyen[$e->moyen] = ($parMoyen[$e->moyen] ?? 0) + $e->montant;
        }
        // Par rayon : tout produit vendu ce jour-là, au comptoir ou sur la facture d'un patient.
        $parRayon = DB::table('visite_lignes')->join('visites', 'visites.id', '=', 'visite_lignes.visite_id')
            ->join('medicaments', 'medicaments.id', '=', 'visite_lignes.medicament_id')
            ->where('visites.etablissement_id', $etablissement->id)->whereBetween('visite_lignes.created_at', [$debut, $fin])
            ->groupBy('medicaments.rayon')->selectRaw('medicaments.rayon as rayon, SUM(visite_lignes.montant) as montant')
            ->pluck('montant', 'rayon')
            ->map(fn (mixed $montant, string|int $rayon): array => [
                'rayon' => (string) $rayon,
                'label' => app(Rayons::class)->nom($etablissement, (string) $rayon),
                'montant' => is_numeric($montant) ? (int) $montant : 0,
            ])->sortByDesc('montant')->values()->all();

        return [
            'ventes' => $toutes->map(fn (Visite $v): array => $this->vente($v))->values()->all(),
            'nombre' => $ventes->count(),
            'chiffre' => $ventes->sum(fn (Visite $v): int => $v->total),
            'encaisse' => array_sum($parMoyen),
            'a_encaisser' => (int) $ventes->where('statut', 'ouverte')->where('credit', false)->sum(fn (Visite $v): int => $v->reste()),
            // Les crédits de tous les jours, pas seulement d'aujourd'hui.
            'credits' => Visite::with('lignes', 'encaissements')->where('etablissement_id', $etablissement->id)
                ->where('credit', true)->where('statut', 'ouverte')->orderBy('ouverte_at')->get()
                ->map(fn (Visite $v): array => $this->vente($v))->values()->all(),
            'par_moyen' => $parMoyen,
            'par_rayon' => $parRayon,
        ];
    }

    /**
     * Une vente, telle que le reçu l'imprime.
     *
     * @return array<string, mixed>
     */
    public function vente(Visite $v): array
    {
        $v->loadMissing('lignes', 'encaissements');
        $etablissement = Etablissement::find($v->etablissement_id);

        return [
            'uuid' => $v->uuid,
            'etablissement' => $etablissement === null ? null : [
                'nom' => $etablissement->nom, 'telephone' => $etablissement->telephone, 'adresse' => $etablissement->adresse,
            ],
            // Le numéro de facture une fois payée ; avant, un repère court.
            'numero' => $v->numero_facture ?? strtoupper(substr($v->uuid, 0, 8)),
            'le' => $v->ouverte_at->toIso8601String(),
            'client' => $v->nom,
            'telephone' => $v->telephone,
            'statut' => match (true) {
                $v->statut === 'annulee' => 'annulee',
                $v->statut === 'ouverte' && $v->reste() > 0 => $v->credit ? 'a_credit' : 'a_encaisser',
                default => 'payee',
            },
            'total' => $v->total,
            'paye' => $v->paye,
            'reste' => $v->reste(),
            'devise' => $v->devise,
            'moyens' => $v->encaissements->whereNull('rembourse_at')->pluck('moyen')->unique()->values()->all(),
            'lignes' => $v->lignes->map(fn ($l): array => [
                'libelle' => $l->libelle, 'quantite' => $l->quantite, 'prix_unitaire' => $l->prix_unitaire, 'montant' => $l->montant,
            ])->values()->all(),
        ];
    }

    /**
     * Annuler une vente du jour : les produits rentrent en stock (dans le lot
     * qui périme le plus tard), l'argent est rendu au client.
     */
    public function annulerVente(Visite $vente, User $par): Visite
    {
        if ($vente->nature !== 'vente' || $vente->statut === 'annulee') {
            throw new Refus('Cette vente ne peut pas être annulée.');
        }
        if (! $vente->ouverte_at->isToday()) {
            throw new Refus('Seule une vente du jour s’annule. Pour un retour plus ancien, faites un inventaire.');
        }

        return DB::transaction(function () use ($vente, $par): Visite {
            foreach ($vente->lignes()->whereNotNull('medicament_id')->get() as $ligne) {
                $this->remettreEnStock($ligne, 'Annulation de vente');
            }
            $vente->encaissements()->whereNull('rembourse_at')->update(['rembourse_at' => now(), 'rembourse_par' => $par->id]);
            $vente->update(['statut' => 'annulee', 'paye' => 0, 'cloturee_at' => now()]);

            return $vente->refresh();
        });
    }

    /**
     * Un médicament facturé revient en rayon (ligne retirée, vente
     * annulée) : dans le lot qui périme le plus tard.
     */
    public function remettreEnStock(VisiteLigne $ligne, string $raison): void
    {
        $m = $ligne->medicament_id !== null ? Medicament::find($ligne->medicament_id) : null;
        $unites = $ligne->unites ?? 0;
        if ($m === null || $unites <= 0) {
            return;
        }
        $lot = Lot::where('medicament_id', $m->id)->orderByRaw('peremption is null desc, peremption desc')->lockForUpdate()->first();
        if ($lot === null) {
            $lot = Lot::create([
                'etablissement_id' => $m->etablissement_id, 'medicament_id' => $m->id, 'numero' => 'RETOUR',
                'quantite' => 0, 'quantite_recue' => 0,
            ]);
        }
        $lot->update(['quantite' => $lot->quantite + $unites]);
        $this->bouger($m, 'entree', $unites, $raison);
    }

    /**
     * Ce qu'il faut recommander : les produits sous le seuil, avec la
     * quantité qui ramène au double du seuil, et le dernier grossiste.
     *
     * @return list<array<string, mixed>>
     */
    public function aCommander(Etablissement $etablissement): array
    {
        $produits = Medicament::where('etablissement_id', $etablissement->id)->where('actif', true)
            ->whereColumn('stock', '<=', 'seuil_alerte')->orderBy('nom')->get();
        $derniers = Lot::with('fournisseur')->whereIn('medicament_id', $produits->pluck('id'))->whereNotNull('fournisseur_id')
            ->latest('id')->get()->unique('medicament_id')->keyBy('medicament_id');

        return array_values($produits->map(function (Medicament $m) use ($derniers): array {
            $boite = max(1, $m->conditionnement);
            $fournisseur = $derniers->get($m->id)?->fournisseur;

            return [
                'uuid' => $m->uuid,
                'nom' => $m->nom,
                'rayon' => $m->rayon,
                'stock_unites' => $m->stock,
                'stock_boites' => intdiv($m->stock, $boite),
                'seuil' => $m->seuil_alerte,
                'boites' => max(1, (int) ceil((2 * $m->seuil_alerte - $m->stock) / $boite)),
                'fournisseur' => $fournisseur === null ? null : ['nom' => $fournisseur->nom, 'telephone' => $fournisseur->telephone],
            ];
        })->all());
    }

    /**
     * Ce qui demande l'attention : stock sous le seuil, péremption proche,
     * lots périmés encore en rayon.
     *
     * @return array{
     *     stock_bas: Collection<int, array{medicament: string, stock: int, seuil: int}>,
     *     peremption_proche: Collection<int, array{medicament: string, lot: string|null, peremption: string|null, quantite: int}>,
     *     perimes: Collection<int, array{medicament: string, lot: string|null, peremption: string|null, quantite: int}>
     * }
     */
    public function alertes(Etablissement $etablissement): array
    {
        $aujourdhui = now()->toDateString();
        $limite = now()->addDays(self::PEREMPTION_PROCHE_JOURS)->toDateString();
        $lots = Lot::with('medicament')
            ->whereHas('medicament')
            ->where('etablissement_id', $etablissement->id)
            ->where('quantite', '>', 0)
            ->whereNotNull('peremption')
            ->where('peremption', '<=', $limite)
            ->orderBy('peremption')
            ->get();
        $ligne = fn (Lot $l): array => [
            'medicament' => $l->medicament !== null ? $l->medicament->nom : '',
            'lot' => $l->numero,
            'peremption' => $l->peremption?->toDateString(),
            'quantite' => $l->quantite,
        ];

        return [
            'stock_bas' => Medicament::where('etablissement_id', $etablissement->id)
                ->where('actif', true)
                ->whereColumn('stock', '<=', 'seuil_alerte')
                ->orderBy('nom')
                ->get()
                ->map(fn (Medicament $m): array => ['medicament' => $m->nom, 'stock' => $m->stock, 'seuil' => $m->seuil_alerte]),
            'peremption_proche' => $lots->filter(fn (Lot $l): bool => ($l->peremption?->toDateString() ?? '') >= $aujourdhui)->map($ligne)->values(),
            'perimes' => $lots->filter(fn (Lot $l): bool => ($l->peremption?->toDateString() ?? '') < $aujourdhui)->map($ligne)->values(),
        ];
    }

    /** Sommes SQL : chaîne, entier ou null selon la base. */
    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private function bouger(Medicament $medicament, string $type, int $delta, string $commentaire): void
    {
        $medicament->refresh();
        $medicament->update(['stock' => max(0, $medicament->stock + $delta)]);
        MouvementStock::create([
            'etablissement_id' => $medicament->etablissement_id,
            'medicament_id' => $medicament->id,
            'type' => $type,
            'quantite' => abs($delta),
            'commentaire' => $commentaire,
        ]);
    }
}
