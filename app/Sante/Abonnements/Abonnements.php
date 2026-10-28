<?php

declare(strict_types=1);

namespace App\Sante\Abonnements;

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Models\JournalAbonnement;
use App\Models\Personne;
use App\Models\User;
use App\Sante\Module;
use App\Sante\Permission;
use App\Sante\Refus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * L'abonnement d'un établissement, comme dans Ngoni Caisse :
 * - en cours, la formule ouvre ses modules et ses fonctions (écrans, rapport) ;
 * - échu, le soin continue (file, consultation, dossiers, caisse, ventes) mais
 *   la gestion se ferme : équipe, réglages, stock, statistiques, badges.
 * Le côté patient ne dépend jamais de l'abonnement.
 */
final class Abonnements
{
    /** Ce que l'on ne peut plus faire une fois l'abonnement échu. */
    public const GESTION = [
        Permission::EquipeGerer,
        Permission::EtablissementRegler,
        Permission::StockGerer,
        Permission::StatsVoir,
        Permission::BadgesGerer,
    ];

    /** L'essai, créé avec l'établissement. */
    public function commencerEssai(Etablissement $etab): Abonnement
    {
        $jours = Formule::where('code', Formule::ESSAI)->value('jours_essai');

        $abo = Abonnement::firstOrCreate(['etablissement_id' => $etab->id], [
            'formule' => Formule::ESSAI,
            'debut' => today(),
            'fin' => today()->addDays(is_numeric($jours) ? (int) $jours : 14),
        ]);
        if ($abo->wasRecentlyCreated) {
            JournalAbonnement::create([
                'etablissement_id' => $etab->id, 'type' => 'essai', 'formule' => Formule::ESSAI,
                'debut' => $abo->debut, 'fin' => $abo->fin,
            ]);
        }

        return $abo;
    }

    public function de(Etablissement $etab): ?Abonnement
    {
        return Abonnement::where('etablissement_id', $etab->id)->first();
    }

    /** Sans abonnement connu (établissement d'avant), rien n'est retiré. */
    public function enCours(Etablissement $etab): bool
    {
        return $this->de($etab)?->enCours() ?? true;
    }

    /**
     * Les modules que la formule laisse ouverts. Échu, on garde ceux de
     * l'établissement : le soin ne s'arrête pas.
     *
     * @return list<string>|null null : pas de limite
     */
    public function modulesPermis(Etablissement $etab): ?array
    {
        $abo = $this->de($etab);
        if ($abo === null || ! $abo->enCours()) {
            return null;
        }

        return Formule::where('code', $abo->formule)->first()?->modules;
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<Permission>
     */
    public function filtrer(array $permissions, Etablissement $etab): array
    {
        if ($this->enCours($etab)) {
            return $permissions;
        }

        return array_values(array_filter(
            $permissions,
            static fn (Permission $p): bool => ! in_array($p, self::GESTION, true),
        ));
    }

    public function aFonction(Etablissement $etab, string $fonction): bool
    {
        $abo = $this->de($etab);
        if ($abo === null) {
            return true;
        }
        if (! $abo->enCours()) {
            return false;
        }

        $formule = Formule::where('code', $abo->formule)->first();

        return $formule !== null && in_array($fonction, $formule->fonctions, true);
    }

    /** Refuse d'ajouter un membre au-delà de ce que la formule permet. */
    public function verifierPlaceDansEquipe(Etablissement $etab): void
    {
        $abo = $this->de($etab);
        if ($abo === null) {
            return;
        }
        if (! $abo->enCours()) {
            throw new Refus('L’abonnement est échu : renouvelez-le pour ajouter un membre à l’équipe.');
        }
        $max = Formule::where('code', $abo->formule)->value('max_membres');
        if (! is_numeric($max)) {
            return;
        }
        $membres = User::where('etablissement_id', $etab->id)->where('actif', true)->count();
        if ($membres >= (int) $max) {
            throw new Refus("Votre formule permet {$max} membres dans l’équipe. Passez à la formule au-dessus pour en ajouter.");
        }
    }

    /**
     * Ce que l'app affiche : la formule, jusqu'à quand, ce qu'elle ouvre.
     *
     * @return array<string, mixed>
     */
    public function etat(Etablissement $etab): array
    {
        $abo = $this->de($etab);
        $formule = $abo !== null ? Formule::where('code', $abo->formule)->first() : null;
        $enCours = $abo?->enCours() ?? true;
        $attente = DemandeAbonnement::where('etablissement_id', $etab->id)->where('statut', 'en_attente')->latest()->first();

        return [
            'formule' => $abo?->formule,
            'formule_nom' => $formule?->nom,
            'essai' => $abo?->formule === Formule::ESSAI,
            'en_cours' => $enCours,
            'fin' => $abo?->fin?->toDateString(),
            'jours_restants' => $abo?->fin !== null && $enCours ? (int) today()->diffInDays($abo->fin) : null,
            'max_membres' => $formule?->max_membres,
            'membres' => User::where('etablissement_id', $etab->id)->where('actif', true)->count(),
            'modules' => $formule?->modules,
            'fonctions' => $enCours && $formule !== null ? $formule->fonctions : [],
            'demande_en_attente' => $attente !== null ? $this->demande($attente) : null,
            'verrous' => $this->verrous($enCours ? $formule : null),
        ];
    }

    /**
     * Ce que la formule n'ouvre pas, et la première formule qui l'ouvre :
     * l'app l'affiche avec un cadenas (« Avec la formule Pro »). Échu, les
     * fonctions en plus se ferment jusqu'au renouvellement.
     *
     * @return array<string, string>
     */
    private function verrous(?Formule $formule): array
    {
        $catalogue = Formule::where('actif', true)->where('code', '!=', Formule::ESSAI)->orderBy('ordre')->get();
        $verrous = [];
        foreach ([...array_map(static fn (Module $m): string => $m->value, Module::cases()), ...array_keys(Formule::FONCTIONS)] as $code) {
            $fonction = array_key_exists($code, Formule::FONCTIONS);
            $ouvert = $formule !== null && in_array($code, $fonction ? $formule->fonctions : $formule->modules, true);
            if ($ouvert || (! $fonction && $formule === null)) {
                continue;
            }
            $avec = $catalogue->first(fn (Formule $f): bool => in_array($code, $fonction ? $f->fonctions : $f->modules, true));
            if ($avec !== null) {
                $verrous[$code] = $avec->nom;
            }
        }

        return $verrous;
    }

    /**
     * Combien d'établissements la personne a créés et peut créer. Le plafond
     * vient de la meilleure formule en cours parmi ses établissements (celle
     * de l'essai tant qu'aucun n'est abonné) ; « source » dit laquelle, pour
     * que l'app l'explique. Chacun paie son abonnement. Les démonstrations de
     * l'éditeur sont à part : elles ne comptent pas et ne donnent aucun droit.
     *
     * @return array{crees: int, max: int|null, restants: int|null, source: array{formule: string, etablissement: string|null}}
     */
    public function limiteEtablissements(Personne $personne): array
    {
        $siens = Etablissement::where('proprietaire_id', $personne->id)->where('interne', false)->get();
        $essai = Formule::where('code', Formule::ESSAI)->first();
        $meilleur = ['max' => $essai?->max_etablissements, 'formule' => $essai->nom ?? 'Essai gratuit', 'etablissement' => null];
        $premier = true;
        foreach ($siens as $etab) {
            $abo = $this->de($etab);
            $formule = $abo !== null && $abo->enCours() ? Formule::where('code', $abo->formule)->first() : null;
            if ($formule === null) {
                continue;
            }
            $plafond = $formule->max_etablissements;
            $plusGrand = $plafond === null || ($meilleur['max'] !== null && $plafond > $meilleur['max']);
            if ($premier || $plusGrand) {
                $meilleur = ['max' => $plafond, 'formule' => $formule->nom, 'etablissement' => $etab->nom];
                $premier = false;
            }
        }
        $crees = $siens->count();
        $max = $meilleur['max'];

        return [
            'crees' => $crees,
            'max' => $max,
            'restants' => $max === null ? null : max(0, $max - $crees),
            'source' => ['formule' => $meilleur['formule'], 'etablissement' => $meilleur['etablissement']],
        ];
    }

    /**
     * Le catalogue des formules payantes, avec leurs tarifs.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(bool $tout = false): array
    {
        $formules = Formule::with('tarifs')
            ->when(! $tout, fn ($q) => $q->where('actif', true)->where('code', '!=', Formule::ESSAI))
            ->orderBy('ordre')->get();
        $catalogue = [];
        foreach ($formules as $f) {
            $tarifs = [];
            foreach ($f->tarifs as $t) {
                $tarifs[] = ['mois' => $t->mois, 'montant' => $t->montant, 'devise' => $t->devise];
            }
            $catalogue[] = [
                'code' => $f->code,
                'nom' => $f->nom,
                'description' => $f->description,
                'modules' => $f->modules,
                'fonctions' => $f->fonctions,
                'jours_essai' => $f->jours_essai,
                'max_membres' => $f->max_membres,
                'max_etablissements' => $f->max_etablissements,
                'actif' => $f->actif,
                'tarifs' => $tarifs,
            ];
        }

        return $catalogue;
    }

    /**
     * L'établissement a payé : sa demande attend la plateforme.
     *
     * @param  array{formule: string, mois: int, moyen: string, telephone?: string|null, note?: string|null, preuve_chemin?: string|null}  $d
     */
    public function demander(Etablissement $etab, ?int $demandeurId, array $d): DemandeAbonnement
    {
        if (DemandeAbonnement::where('etablissement_id', $etab->id)->where('statut', 'en_attente')->exists()) {
            throw new Refus('Une demande attend déjà la validation de Ngoni Santé.');
        }
        $formule = Formule::where('code', $d['formule'])->where('actif', true)->where('code', '!=', Formule::ESSAI)->first();
        $tarif = $formule?->tarifs()->where('mois', $d['mois'])->first();
        if ($formule === null || $tarif === null) {
            throw new Refus('Cette formule ou cette durée n’est pas proposée.');
        }

        return DemandeAbonnement::create([
            'etablissement_id' => $etab->id,
            'demandeur_id' => $demandeurId,
            'formule' => $formule->code,
            'mois' => $tarif->mois,
            'montant' => $tarif->montant,
            'devise' => $tarif->devise,
            'moyen' => $d['moyen'],
            'telephone' => $d['telephone'] ?? null,
            'note' => $d['note'] ?? null,
            'preuve_chemin' => $d['preuve_chemin'] ?? null,
            'statut' => 'en_attente',
        ]);
    }

    /**
     * La plateforme valide : l'abonnement court à partir de sa fin (même
     * formule) ou d'aujourd'hui (autre formule, ou échu), ou sans échéance
     * (`$aVie`). Le reçu reçoit son numéro « A26-#### » et prend le montant,
     * le moyen et la référence validés.
     *
     * @param  string|null  $parNom  quand ce n'est pas une personne : « Console · Ismaila SACKO »
     */
    public function approuver(
        DemandeAbonnement $demande,
        ?int $parId,
        ?int $montant = null,
        ?string $note = null,
        ?string $moyen = null,
        ?string $reference = null,
        bool $aVie = false,
        ?string $parNom = null,
    ): DemandeAbonnement {
        if ($demande->statut !== 'en_attente') {
            throw new Refus('Cette demande a déjà été traitée.');
        }
        // Un prix négocié : le montant validé remplace le montant demandé, gardé à côté.
        if ($montant !== null && $montant !== $demande->montant) {
            $demande->update(['montant_demande' => $demande->montant, 'montant' => $montant]);
        }
        if ($note !== null && $note !== '') {
            $demande->update(['note_validation' => $note]);
        }
        if ($moyen !== null && $moyen !== '') {
            $demande->update(['moyen' => $moyen]);
        }
        if ($reference !== null && $reference !== '') {
            $demande->update(['reference' => $reference]);
        }

        return DB::transaction(function () use ($demande, $parId, $parNom, $aVie): DemandeAbonnement {
            $abo = Abonnement::firstOrNew(['etablissement_id' => $demande->etablissement_id]);
            $prolonge = $abo->exists && $abo->formule === $demande->formule && $abo->fin !== null && $abo->enCours();
            $debut = $prolonge ? $abo->fin->copy()->addDay() : today();
            $fin = $aVie ? null : $debut->copy()->addMonthsNoOverflow($demande->mois)->subDay();

            $abo->fill([
                'formule' => $demande->formule,
                'debut' => $prolonge ? $abo->debut : $debut,
                'fin' => $fin,
                'manuel' => false,
                'accorde_par' => $parId,
                'accorde_par_nom' => $parNom,
            ])->save();

            $demande->update([
                'statut' => 'approuvee',
                'decide_at' => now(),
                'decide_par' => $parId,
                'decide_par_nom' => $parNom,
                'periode_debut' => $debut,
                'periode_fin' => $fin,
                'recu_numero' => $this->numeroRecu(),
            ]);

            return $demande;
        });
    }

    public function refuser(DemandeAbonnement $demande, ?int $parId, string $motif, ?string $parNom = null): DemandeAbonnement
    {
        if ($demande->statut !== 'en_attente') {
            throw new Refus('Cette demande a déjà été traitée.');
        }
        $demande->update([
            'statut' => 'refusee', 'decide_at' => now(), 'decide_par' => $parId, 'decide_par_nom' => $parNom, 'motif_refus' => $motif,
        ]);

        return $demande;
    }

    /**
     * La plateforme accorde à la main : une formule, jusqu'à une date (gardée
     * telle quelle) ou sans échéance. La période commence à `$debut`, sinon
     * au lendemain de l'abonnement en cours (même formule) ou aujourd'hui.
     * Avec un montant, c'est un paiement : reçu numéroté et recette, et la
     * demande en attente de l'établissement est soldée par ce reçu.
     *
     * @param  string|null  $parNom  quand ce n'est pas une personne : « Console · Ismaila SACKO »
     */
    public function accorder(
        Etablissement $etab,
        string $formule,
        ?Carbon $fin,
        ?int $parId,
        ?string $note,
        ?Carbon $debut = null,
        ?int $montant = null,
        ?string $moyen = null,
        ?string $reference = null,
        ?string $parNom = null,
    ): Abonnement {
        if (! Formule::where('code', $formule)->exists()) {
            throw new Refus('Formule inconnue.');
        }
        $paye = $montant !== null && $montant > 0;
        if ($paye && ($moyen === null || $moyen === '')) {
            throw new Refus('Indiquez le moyen de paiement.');
        }

        return DB::transaction(function () use ($etab, $formule, $fin, $parId, $note, $debut, $montant, $moyen, $reference, $parNom, $paye): Abonnement {
            $abo = Abonnement::firstOrNew(['etablissement_id' => $etab->id]);
            $prolonge = $abo->exists && $abo->formule === $formule && $abo->fin !== null && $abo->enCours();
            if ($debut === null) {
                $debut = $prolonge ? $abo->fin->copy()->addDay() : today();
                if ($fin !== null && $debut->gt($fin)) {
                    $debut = today();
                }
            }

            $abo->fill([
                'formule' => $formule,
                'debut' => $prolonge ? $abo->debut : $debut->toDateString(),
                'fin' => $fin?->toDateString(),
                'manuel' => true,
                'accorde_par' => $parId,
                'accorde_par_nom' => $parNom,
                'note' => $note,
            ])->save();

            $recu = null;
            if ($paye) {
                $recu = DemandeAbonnement::where('etablissement_id', $etab->id)->where('statut', 'en_attente')->latest()->first()
                    ?? new DemandeAbonnement(['etablissement_id' => $etab->id, 'accord' => true, 'montant' => $montant]);
                $recu->fill([
                    'formule' => $formule,
                    'mois' => $fin === null ? 0 : max(1, min(255, (int) round($debut->diffInMonths($fin->copy()->addDay(), true)))),
                    'montant_demande' => $recu->exists && $recu->montant !== $montant ? $recu->montant : null,
                    'montant' => $montant,
                    'devise' => 'XOF',
                    'moyen' => $moyen,
                    'reference' => $reference !== null && $reference !== '' ? $reference : $recu->reference,
                    'note_validation' => $note,
                    'statut' => 'approuvee',
                    'decide_at' => now(),
                    'decide_par' => $parId,
                    'decide_par_nom' => $parNom,
                    'periode_debut' => $debut->toDateString(),
                    'periode_fin' => $fin?->toDateString(),
                    'recu_numero' => $this->numeroRecu(),
                ])->save();
            }

            JournalAbonnement::create([
                'etablissement_id' => $etab->id,
                'type' => 'accord',
                'formule' => $formule,
                'debut' => $debut->toDateString(),
                'fin' => $fin?->toDateString(),
                'montant' => $paye ? $montant : null,
                'devise' => $paye ? 'XOF' : null,
                'moyen' => $paye ? $moyen : null,
                'reference' => $reference,
                'demande_id' => $recu?->id,
                'par_id' => $parId,
                'par_nom' => $parNom,
                'note' => $note,
            ]);

            return $abo;
        });
    }

    /** Un essai, en cours ou fini, peut être prolongé ; pas une formule payante. */
    public function essaiProlongeable(?Abonnement $abo): bool
    {
        return $abo !== null && $abo->formule === Formule::ESSAI;
    }

    /**
     * La plateforme prolonge l'essai gratuit jusqu'à `$fin` (incluse) : il
     * reste un essai (mêmes modules, badge « essai »), sans reçu ni recette,
     * et une demande en attente reste en attente.
     *
     * @param  string|null  $parNom  quand ce n'est pas une personne : « Console · Ismaila SACKO »
     */
    public function prolongerEssai(Etablissement $etab, Carbon $fin, ?string $note, ?int $parId, ?string $parNom = null): Abonnement
    {
        $abo = $this->de($etab);
        if ($abo === null || ! $this->essaiProlongeable($abo)) {
            throw new Refus('Seul un essai se prolonge : cet établissement a une formule. Accordez-lui plutôt une formule.');
        }
        if ($fin->lt(today())) {
            throw new Refus('La fin de l’essai doit être aujourd’hui ou plus tard.');
        }

        return DB::transaction(function () use ($abo, $etab, $fin, $note, $parId, $parNom): Abonnement {
            $debut = $abo->fin !== null && $abo->enCours() ? $abo->fin->copy()->addDay() : today();
            $abo->update([
                'fin' => $fin->toDateString(),
                'manuel' => true,
                'accorde_par' => $parId,
                'accorde_par_nom' => $parNom,
                'note' => $note,
            ]);
            JournalAbonnement::create([
                'etablissement_id' => $etab->id,
                'type' => 'essai',
                'formule' => Formule::ESSAI,
                'debut' => ($debut->gt($fin) ? $fin : $debut)->toDateString(),
                'fin' => $fin->toDateString(),
                'par_id' => $parId,
                'par_nom' => $parNom,
                'note' => 'Essai prolongé jusqu’au '.$fin->format('d/m/Y').($note !== null && $note !== '' ? ' · '.$note : ''),
            ]);

            return $abo;
        });
    }

    /** L'abonnement s'arrête maintenant : hier était son dernier jour. */
    public function revoquer(Etablissement $etab, string $motif, ?int $parId, ?string $parNom = null): Abonnement
    {
        $abo = $this->de($etab);
        if ($abo === null || ! $abo->enCours()) {
            throw new Refus('Aucun abonnement en cours à arrêter.');
        }

        return DB::transaction(function () use ($abo, $etab, $motif, $parId, $parNom): Abonnement {
            $abo->update([
                'fin' => today()->subDay()->toDateString(),
                'manuel' => true,
                'accorde_par' => $parId,
                'accorde_par_nom' => $parNom,
                'note' => $motif,
            ]);
            JournalAbonnement::create([
                'etablissement_id' => $etab->id,
                'type' => 'revocation',
                'formule' => $abo->formule,
                'fin' => $abo->fin,
                'par_id' => $parId,
                'par_nom' => $parNom,
                'note' => $motif,
            ]);

            return $abo;
        });
    }

    /**
     * Tout ce qui est arrivé à l'abonnement de l'établissement, du plus récent
     * au plus ancien : essai, demandes, paiements, formules accordées, révocations.
     *
     * @return list<array<string, mixed>>
     */
    public function historique(Etablissement $etab): array
    {
        $noms = Formule::pluck('nom', 'code');
        $lignes = [];
        $journal = JournalAbonnement::with(['demande', 'par'])->where('etablissement_id', $etab->id)->orderByDesc('id')->get();
        // Une demande soldée par un accord payé n'apparaît qu'une fois : sur la ligne de l'accord.
        $soldees = $journal->pluck('demande_id')->filter()->all();
        $demandes = DemandeAbonnement::with('decideur')->where('etablissement_id', $etab->id)->where('accord', false)->whereNotIn('id', $soldees)->orderByDesc('id')->get();
        foreach ($demandes as $d) {
            $paye = $d->statut === 'approuvee';
            $lignes[] = [
                'type' => $paye ? 'paiement' : 'demande',
                'le' => ($paye && $d->decide_at !== null ? $d->decide_at : $d->created_at)->toIso8601String(),
                'formule' => $d->formule,
                'formule_nom' => $noms[$d->formule] ?? $d->formule,
                'mois' => $d->mois,
                'debut' => $d->periode_debut?->toDateString(),
                'fin' => $d->periode_fin?->toDateString(),
                'montant' => $d->montant,
                'devise' => $d->devise,
                'moyen' => $d->moyen,
                'reference' => $d->reference,
                'recu_numero' => $d->recu_numero,
                'statut' => $d->statut,
                'par' => $d->decide_par_nom ?? $d->decideur?->nom,
                'note' => $d->motif_refus ?? $d->note_validation ?? $d->note,
            ];
        }
        foreach ($journal as $j) {
            $lignes[] = [
                'type' => $j->type,
                'le' => $j->created_at->toIso8601String(),
                'formule' => $j->formule,
                'formule_nom' => $j->formule !== null ? ($noms[$j->formule] ?? $j->formule) : null,
                'mois' => $j->demande?->mois,
                'debut' => $j->debut?->toDateString(),
                'fin' => $j->fin?->toDateString(),
                'montant' => $j->montant,
                'devise' => $j->devise,
                'moyen' => $j->moyen,
                'reference' => $j->reference,
                'recu_numero' => $j->demande?->recu_numero,
                'statut' => null,
                'par' => $j->par_nom ?? $j->par?->nom,
                'note' => $j->note,
            ];
        }
        // Tri stable : à la même seconde, le plus récent reste devant.
        usort($lignes, static fn (array $a, array $b): int => strcmp((string) $b['le'], (string) $a['le']));

        return $lignes;
    }

    /**
     * @return array<string, mixed>
     */
    public function demande(DemandeAbonnement $d): array
    {
        return [
            'uuid' => $d->uuid,
            'formule' => $d->formule,
            'formule_nom' => Formule::where('code', $d->formule)->value('nom'),
            'mois' => $d->mois,
            'montant' => $d->montant,
            'montant_demande' => $d->montant_demande,
            'note_validation' => $d->note_validation,
            'devise' => $d->devise,
            'moyen' => $d->moyen,
            'reference' => $d->reference,
            'telephone' => $d->telephone,
            'note' => $d->note,
            'preuve' => $d->preuve_chemin !== null,
            'statut' => $d->statut,
            'motif_refus' => $d->motif_refus,
            'recu_numero' => $d->recu_numero,
            'periode_debut' => $d->periode_debut?->toDateString(),
            'periode_fin' => $d->periode_fin?->toDateString(),
            'le' => $d->created_at->toIso8601String(),
        ];
    }

    /** « A26-4821 » : l'année, puis quatre chiffres au hasard, jamais deux fois. */
    private function numeroRecu(): string
    {
        $prefixe = 'A'.now()->format('y').'-';
        for ($i = 0; $i < 50; $i++) {
            $numero = $prefixe.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (! DemandeAbonnement::where('recu_numero', $numero)->exists()) {
                return $numero;
            }
        }

        return $prefixe.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }
}
