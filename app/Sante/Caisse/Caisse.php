<?php

declare(strict_types=1);

namespace App\Sante\Caisse;

use App\Models\Assureur;
use App\Models\Encaissement;
use App\Models\Etablissement;
use App\Models\Ordonnance;
use App\Models\Pays;
use App\Models\SessionCaisse;
use App\Models\Tarif;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visite;
use App\Models\VisiteLigne;
use App\Sante\File\Statut;
use App\Sante\Refus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La caisse unifiée : une visite, une facture, des paiements (espèces,
 * Mobile Money), et la session de caisse de chaque caissier.
 */
final class Caisse
{
    /**
     * La visite d'un nouveau ticket : celle du ticket précédent (transfert),
     * sinon la visite ouverte aujourd'hui de la même personne, sinon une
     * nouvelle.
     *
     * @param  array{nom?: string|null, telephone?: string|null, personne_id?: int|null, patient_id?: int|null, dossier_id?: int|null, ticket_precedent_id?: int|null}  $qui
     */
    public function visitePourTicket(Etablissement $etablissement, array $qui): Visite
    {
        $precedent = isset($qui['ticket_precedent_id']) ? Ticket::find($qui['ticket_precedent_id']) : null;
        if ($precedent?->visite_id !== null && ($visite = Visite::find($precedent->visite_id)) !== null) {
            return $visite;
        }

        // Le même patient revenu dans la journée garde sa visite ouverte.
        $personneId = $qui['personne_id'] ?? null;
        $dossierId = $qui['dossier_id'] ?? null;
        if ($personneId !== null || $dossierId !== null) {
            // Par son dossier ou par son compte : la visite ouverte depuis l'app
            // et celle de l'accueil sont la même.
            $ouverte = Visite::where('etablissement_id', $etablissement->id)
                ->where(fn ($q) => $q
                    ->when($dossierId !== null, fn ($w) => $w->orWhere('dossier_id', $dossierId))
                    ->when($personneId !== null, fn ($w) => $w->orWhere('personne_id', $personneId)))
                ->where('statut', 'ouverte')
                ->where('nature', 'visite')
                ->where('ouverte_at', '>=', now()->subHours(16))
                ->latest('ouverte_at')
                ->first();
            if ($ouverte !== null) {
                if ($ouverte->dossier_id === null && $dossierId !== null) {
                    $ouverte->update(['dossier_id' => $dossierId]);
                }

                return $ouverte;
            }
        }

        return Visite::create([
            'etablissement_id' => $etablissement->id,
            'personne_id' => $personneId,
            'dossier_id' => $dossierId,
            'patient_id' => $qui['patient_id'] ?? null,
            'nom' => $qui['nom'] ?? null,
            'telephone' => $qui['telephone'] ?? null,
            'devise' => $etablissement->devise,
            'statut' => 'ouverte',
            'ouverte_at' => now(),
        ]);
    }

    public function ajouterLigne(Visite $visite, ?Tarif $tarif, ?string $libelle, ?int $prix, int $quantite, ?User $par): VisiteLigne
    {
        $this->ouverte($visite);
        $prixUnitaire = $tarif !== null ? $tarif->prix : (int) $prix;
        if ($tarif === null && ($libelle === null || $libelle === '' || $prix === null)) {
            throw new Refus('Indiquez un acte du tarif, ou un libellé et un prix.');
        }

        $ligne = $visite->lignes()->create([
            'tarif_id' => $tarif?->id,
            'libelle' => $tarif !== null ? $tarif->nom : (string) $libelle,
            'quantite' => max(1, $quantite),
            'prix_unitaire' => $prixUnitaire,
            'montant' => $prixUnitaire * max(1, $quantite),
            'ajoute_par' => $par?->id,
        ]);
        $this->recalculer($visite);

        return $ligne;
    }

    public function retirerLigne(Visite $visite, VisiteLigne $ligne): void
    {
        $this->ouverte($visite);
        if ($visite->paye > $visite->total - $ligne->montant) {
            throw new Refus('Cette ligne est déjà payée : remboursez d’abord.');
        }
        $ligne->delete();
        $this->recalculer($visite);
    }

    /**
     * Encaisse sur la visite, dans la session ouverte du caissier. On ne
     * peut pas encaisser plus que le reste à payer.
     */
    public function encaisser(Visite $visite, int $montant, string $moyen, ?string $reference, User $caissier): Encaissement
    {
        $session = $this->sessionOuverte($caissier);
        if ($session === null) {
            throw new Refus('Ouvrez votre caisse avant d’encaisser.');
        }

        return DB::transaction(function () use ($visite, $montant, $moyen, $reference, $caissier, $session): Encaissement {
            $visite = Visite::whereKey($visite->id)->lockForUpdate()->firstOrFail();
            $reste = $visite->reste();
            if ($montant <= 0 || $montant > $reste) {
                throw new Refus($reste <= 0 ? 'Cette visite est déjà payée.' : 'Le montant dépasse le reste à payer.');
            }
            if ($moyen !== 'especes' && ($reference === null || trim($reference) === '')) {
                throw new Refus('Indiquez la référence de la transaction Mobile Money.');
            }

            $encaissement = $visite->encaissements()->create([
                'session_caisse_id' => $session->id,
                'montant' => $montant,
                'moyen' => $moyen,
                'reference' => $reference !== null ? trim($reference) : null,
                'encaisse_par' => $caissier->id,
            ]);
            $visite->update(['paye' => $visite->paye + $montant]);
            if ($visite->numero_facture === null) {
                $visite->update(['numero_facture' => $this->prochainNumeroFacture($visite->etablissement_id)]);
            }
            // Tout est payé : le passage à la caisse est fini.
            if ($visite->reste() === 0) {
                $this->terminerTickets($visite, fn ($q) => $q->whereHas('service', fn ($s) => $s->where('nom', 'like', 'Caisse%')));
                // Une vente du comptoir payée est finie : le client repart avec ses produits.
                if ($visite->nature === 'vente') {
                    $visite->update(['statut' => 'cloturee', 'cloturee_at' => now()]);
                }
            }

            return $encaissement;
        });
    }

    /**
     * « F26-4821 » : l'année et quatre chiffres au hasard, jamais deux fois
     * dans l'établissement (cinq chiffres si l'année est presque pleine).
     * Appelé dans la transaction de l'encaissement, l'établissement
     * verrouillé : deux caisses n'obtiennent jamais le même numéro.
     */
    private function prochainNumeroFacture(int $etablissementId): string
    {
        Etablissement::whereKey($etablissementId)->lockForUpdate()->first();
        $prefixe = 'F'.now()->format('y').'-';
        for ($essai = 0; $essai < 60; $essai++) {
            $chiffres = $essai < 50 ? 4 : 5;
            $numero = $prefixe.str_pad((string) random_int(0, 10 ** $chiffres - 1), $chiffres, '0', STR_PAD_LEFT);
            if (! Visite::where('etablissement_id', $etablissementId)->where('numero_facture', $numero)->exists()) {
                return $numero;
            }
        }

        throw new Refus('Impossible de donner un numéro de facture : réessayez.');
    }

    /**
     * Les moyens de paiement du pays de l'établissement, les espèces toujours.
     *
     * @return list<string>
     */
    public function moyensDuPays(Etablissement $etablissement): array
    {
        $pays = Pays::find($etablissement->pays_code);
        $moyens = $pays !== null ? $pays->moyens_paiement : ['especes'];

        return in_array('especes', $moyens, true) ? $moyens : ['especes', ...$moyens];
    }

    public function cloturerVisite(Visite $visite): Visite
    {
        $visite->update(['statut' => 'cloturee', 'cloturee_at' => now()]);
        // Le patient est parti : ses tickets encore dans une file aussi.
        $this->terminerTickets($visite);

        return $visite;
    }

    public function sessionOuverte(User $membre): ?SessionCaisse
    {
        return SessionCaisse::where('membre_id', $membre->id)->whereNull('cloturee_at')->latest('ouverte_at')->first();
    }

    public function ouvrirSession(User $membre, int $fond): SessionCaisse
    {
        return $this->sessionOuverte($membre) ?? SessionCaisse::create([
            'etablissement_id' => $membre->etablissement_id,
            'membre_id' => $membre->id,
            'fond_initial' => $fond,
            'ouverte_at' => now(),
        ]);
    }

    /** Clôture : l'écart entre les espèces comptées et celles attendues. */
    public function cloturerSession(SessionCaisse $session, int $especesComptees): SessionCaisse
    {
        $attendu = $session->fond_initial + $this->totauxSession($session)['especes'];
        $session->update([
            'cloturee_at' => now(),
            'especes_comptees' => $especesComptees,
            'ecart' => $especesComptees - $attendu,
        ]);

        return $session;
    }

    /**
     * Encaissé dans la session, par moyen de paiement.
     *
     * @return array<string, int>
     */
    public function totauxSession(SessionCaisse $session): array
    {
        /** @var array<string, int> $totaux */
        $totaux = Encaissement::where('session_caisse_id', $session->id)->whereNull('rembourse_at')
            ->selectRaw('moyen, sum(montant) as total')
            ->groupBy('moyen')
            ->pluck('total', 'moyen')
            ->map(fn ($t): int => self::entier($t))
            ->all();

        return ['especes' => 0, ...$totaux];
    }

    /**
     * Le rapport d'une journée : encaissé par moyen, facturé par catégorie
     * d'acte, et ce qui reste impayé.
     *
     * @return array<string, mixed>
     */
    public function rapportJour(Etablissement $etablissement, string $jour): array
    {
        $fuseau = Pays::whereKey($etablissement->pays_code)->value('fuseau');
        $fuseau = is_string($fuseau) ? $fuseau : 'Africa/Bamako';
        $debut = Carbon::parse($jour, $fuseau)->startOfDay()->utc();
        $fin = Carbon::parse($jour, $fuseau)->endOfDay()->utc();

        $visites = Visite::where('etablissement_id', $etablissement->id)->whereBetween('ouverte_at', [$debut, $fin])->get();
        $encaisse = Encaissement::whereIn('visite_id', Visite::where('etablissement_id', $etablissement->id)->pluck('id'))
            ->whereBetween('created_at', [$debut, $fin])->whereNull('rembourse_at')
            ->selectRaw('moyen, sum(montant) as total, count(*) as nombre')
            ->groupBy('moyen')
            ->get()
            ->map(fn ($e): array => ['moyen' => $e->getAttribute('moyen'), 'total' => self::entier($e->getAttribute('total')), 'nombre' => self::entier($e->getAttribute('nombre'))]);
        $parCategorie = VisiteLigne::whereIn('visite_id', $visites->pluck('id'))
            ->leftJoin('tarifs', 'tarifs.id', '=', 'visite_lignes.tarif_id')
            ->selectRaw("coalesce(tarifs.categorie, 'autre') as categorie, sum(visite_lignes.montant) as total")
            ->groupBy('categorie')
            ->get()
            ->map(fn ($l): array => ['categorie' => $l->getAttribute('categorie'), 'total' => self::entier($l->getAttribute('total'))]);

        return [
            'jour' => $jour,
            'devise' => $etablissement->devise,
            'visites' => $visites->count(),
            'facture' => self::entier($visites->sum('total')),
            'encaisse' => self::entier($encaisse->sum('total')),
            'impaye' => self::entier($visites->sum(fn (Visite $v): int => $v->reste())),
            'part_assureurs' => self::entier($visites->sum('part_assureur')),
            'par_moyen' => $encaisse->values(),
            'par_categorie' => $parCategorie->values(),
        ];
    }

    /**
     * L'en-tête du reçu : l'établissement, et le numéro du patient chez lui.
     *
     * @return array{etablissement: string|null, adresse: string|null, telephone: string|null, numero_dossier: string|null}
     */
    private function enTeteRecu(Visite $visite): array
    {
        $etab = Etablissement::find($visite->etablissement_id);
        $numero = $visite->dossier_id !== null
            ? DB::table('numeros_dossier')->where('etablissement_id', $visite->etablissement_id)->where('dossier_id', $visite->dossier_id)->value('numero')
            : null;

        return [
            'etablissement' => $etab?->nom,
            'adresse' => $etab?->adresse,
            'telephone' => $etab?->telephone,
            'numero_dossier' => is_string($numero) ? $numero : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pourAffichage(Visite $visite): array
    {
        $visite->loadMissing('lignes', 'encaissements', 'tickets.service', 'assureur');

        return [
            'uuid' => $visite->uuid,
            'nom' => $visite->nom,
            'telephone' => $visite->telephone,
            'statut' => $visite->statut,
            'devise' => $visite->devise,
            'total' => $visite->total,
            'paye' => $visite->paye,
            'part_assureur' => $visite->part_assureur,
            'assurance' => $visite->assureur === null ? null : [
                'nom' => $visite->assureur->nom,
                'taux' => $visite->taux_couverture,
                'numero' => $visite->numero_assure,
            ],
            'reste' => $visite->reste(),
            // Prescrit mais pas encore servi : le médicament n'est pas encore facturé.
            'ordonnances_a_servir' => Ordonnance::where('visite_id', $visite->id)->whereIn('statut', ['emise', 'partielle'])->count(),
            'ouverte_at' => $visite->ouverte_at->toIso8601String(),
            'tickets' => $visite->tickets->map(fn (Ticket $t): array => [
                'code' => $t->code,
                'service' => $t->service->nom,
                'statut' => $t->statut->value,
            ])->values(),
            'lignes' => $visite->lignes->map(fn (VisiteLigne $l): array => [
                'uuid' => $l->uuid,
                'libelle' => $l->libelle,
                'quantite' => $l->quantite,
                'prix_unitaire' => $l->prix_unitaire,
                'montant' => $l->montant,
            ])->values(),
            'encaissements' => $visite->encaissements->map(fn (Encaissement $e): array => [
                'uuid' => $e->uuid,
                'montant' => $e->montant,
                'moyen' => $e->moyen,
                'reference' => $e->reference,
                'at' => $e->created_at?->toIso8601String(),
                'par' => $e->encaisse_par !== null ? User::whereKey($e->encaisse_par)->value('name') : null,
                'rembourse' => $e->rembourse_at !== null,
            ])->values(),
            // Pour le reçu : la facture, l'établissement, le n° de dossier du patient.
            'numero_facture' => $visite->numero_facture,
            'recu' => $this->enTeteRecu($visite),
        ];
    }

    /** Sommes SQL : chaîne, entier ou null selon la base. */
    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private function ouverte(Visite $visite): void
    {
        if ($visite->statut !== 'ouverte') {
            throw new Refus('Cette visite est clôturée.');
        }
    }

    /**
     * Termine les tickets de la visite encore dans une file.
     *
     * @param  (callable(Builder<Ticket>): mixed)|null  $filtre
     */
    private function terminerTickets(Visite $visite, ?callable $filtre = null): void
    {
        $tickets = Ticket::where('visite_id', $visite->id)
            ->whereIn('statut', [Statut::Emis->value, Statut::Appele->value, Statut::EnService->value]);
        if ($filtre !== null) {
            $filtre($tickets);
        }
        $tickets->update(['statut' => Statut::Termine->value, 'termine_at' => now()]);
    }

    private function recalculer(Visite $visite): void
    {
        $total = (int) $visite->lignes()->sum('montant');
        $visite->update(['total' => $total, 'part_assureur' => $this->partAssureur($visite, $total)]);
    }

    /** La part de l'assureur : le taux, dans la limite du plafond. */
    private function partAssureur(Visite $visite, int $total): int
    {
        $assureur = $visite->assureur_id !== null ? Assureur::withTrashed()->find($visite->assureur_id) : null;
        if ($assureur === null || $visite->taux_couverture === null) {
            return 0;
        }
        $part = intdiv($total * $visite->taux_couverture, 100);

        return $assureur->plafond !== null ? min($part, $assureur->plafond) : $part;
    }

    /**
     * Le patient est assuré : la part de l'assureur sort de ce qu'il paie.
     * Sans [assureur], la couverture est retirée.
     */
    public function couvrir(Visite $visite, ?Assureur $assureur, ?string $numero = null, ?int $taux = null): Visite
    {
        $this->ouverte($visite);

        return DB::transaction(function () use ($visite, $assureur, $numero, $taux): Visite {
            $visite = Visite::whereKey($visite->id)->lockForUpdate()->firstOrFail();
            $visite->fill([
                'assureur_id' => $assureur?->id,
                'numero_assure' => $assureur !== null ? $numero : null,
                'taux_couverture' => $assureur !== null ? ($taux ?? $assureur->taux) : null,
            ]);
            $part = $this->partAssureur($visite, $visite->total);
            if ($visite->paye > $visite->total - $part) {
                throw new Refus('Le patient a déjà payé plus que sa part : remboursez d’abord.');
            }
            $visite->part_assureur = $part;
            $visite->save();

            return $visite;
        });
    }

    /**
     * Le bordereau du mois pour un assureur : chaque visite et sa part.
     *
     * @return array{assureur: string, mois: string, devise: string, total: int, lignes: list<array{date: string, patient: string|null, numero_assure: string|null, total: int, taux: int|null, part_assureur: int}>}
     */
    public function bordereau(Etablissement $etablissement, Assureur $assureur, string $mois): array
    {
        $fuseau = Pays::whereKey($etablissement->pays_code)->value('fuseau');
        $debut = Carbon::parse("{$mois}-01", is_string($fuseau) ? $fuseau : 'Africa/Bamako')->startOfMonth();
        $visites = Visite::where('etablissement_id', $etablissement->id)->where('assureur_id', $assureur->id)
            ->whereBetween('ouverte_at', [$debut->copy()->utc(), $debut->copy()->endOfMonth()->utc()])
            ->where('part_assureur', '>', 0)->orderBy('ouverte_at')->get();

        $lignes = [];
        foreach ($visites as $v) {
            $lignes[] = [
                'date' => $v->ouverte_at->toDateString(),
                'patient' => $v->nom,
                'numero_assure' => $v->numero_assure,
                'total' => $v->total,
                'taux' => $v->taux_couverture,
                'part_assureur' => $v->part_assureur,
            ];
        }

        return [
            'assureur' => $assureur->nom,
            'mois' => $mois,
            'devise' => $etablissement->devise,
            'total' => self::entier($visites->sum('part_assureur')),
            'lignes' => $lignes,
        ];
    }
}
