<?php

declare(strict_types=1);

namespace App\Sante\Abonnements;

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Models\User;
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

        return Abonnement::firstOrCreate(['etablissement_id' => $etab->id], [
            'formule' => Formule::ESSAI,
            'debut' => today(),
            'fin' => today()->addDays(is_numeric($jours) ? (int) $jours : 14),
        ]);
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
            'modules' => $formule?->modules,
            'fonctions' => $enCours && $formule !== null ? $formule->fonctions : [],
            'demande_en_attente' => $attente !== null ? $this->demande($attente) : null,
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
     * formule) ou d'aujourd'hui (autre formule, ou échu). Le reçu reçoit son
     * numéro « A26-#### ».
     */
    public function approuver(DemandeAbonnement $demande, ?int $parId, ?int $montant = null, ?string $note = null): DemandeAbonnement
    {
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

        return DB::transaction(function () use ($demande, $parId): DemandeAbonnement {
            $abo = Abonnement::firstOrNew(['etablissement_id' => $demande->etablissement_id]);
            $prolonge = $abo->exists && $abo->formule === $demande->formule && $abo->fin !== null && $abo->enCours();
            $debut = $prolonge ? $abo->fin->copy()->addDay() : today();
            $fin = $debut->copy()->addMonthsNoOverflow($demande->mois)->subDay();

            $abo->fill([
                'formule' => $demande->formule,
                'debut' => $prolonge ? $abo->debut : $debut,
                'fin' => $fin,
                'manuel' => false,
                'accorde_par' => $parId,
            ])->save();

            $demande->update([
                'statut' => 'approuvee',
                'decide_at' => now(),
                'decide_par' => $parId,
                'periode_debut' => $debut,
                'periode_fin' => $fin,
                'recu_numero' => $this->numeroRecu(),
            ]);

            return $demande;
        });
    }

    public function refuser(DemandeAbonnement $demande, ?int $parId, string $motif): DemandeAbonnement
    {
        if ($demande->statut !== 'en_attente') {
            throw new Refus('Cette demande a déjà été traitée.');
        }
        $demande->update(['statut' => 'refusee', 'decide_at' => now(), 'decide_par' => $parId, 'motif_refus' => $motif]);

        return $demande;
    }

    /** La plateforme accorde à la main : une formule, jusqu'à une date ou sans échéance. */
    public function accorder(Etablissement $etab, string $formule, ?Carbon $fin, ?int $parId, ?string $note): Abonnement
    {
        if (! Formule::where('code', $formule)->exists()) {
            throw new Refus('Formule inconnue.');
        }
        $abo = Abonnement::firstOrNew(['etablissement_id' => $etab->id]);
        $abo->fill([
            'formule' => $formule,
            'debut' => $abo->exists ? $abo->debut : today(),
            'fin' => $fin?->toDateString(),
            'manuel' => true,
            'accorde_par' => $parId,
            'note' => $note,
        ])->save();

        return $abo;
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
