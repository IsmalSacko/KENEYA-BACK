<?php

declare(strict_types=1);

namespace App\Sante\Hospitalisation;

use App\Models\Administration;
use App\Models\Chambre;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Hospitalisation;
use App\Models\Lit;
use App\Models\SoinPrescrit;
use App\Models\Tarif;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Caisse\Caisse;
use App\Sante\Qr;
use App\Sante\Rdv\Agendas;
use App\Sante\Refus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * L'hospitalisation : lits, admission, plan de soins, administration au lit
 * après scan du bracelet, sortie facturée à la nuit.
 */
final class Hospitalisations
{
    public const QR = 'bracelet';

    /** Après une heure, une prise non faite est en retard. */
    public const RETARD_MIN = 60;

    /** Une prise notée se corrige pendant ce temps (heures). */
    public const CORRECTION_HEURES = 6;

    public function __construct(private readonly Caisse $caisse, private readonly Agendas $agendas) {}

    /**
     * @param  array{nom: string, service?: string|null, lits: int, prix?: int|null}  $donnees
     */
    public function creerChambre(Etablissement $etablissement, array $donnees): Chambre
    {
        return DB::transaction(function () use ($etablissement, $donnees): Chambre {
            $chambre = Chambre::create([
                'etablissement_id' => $etablissement->id,
                'nom' => $donnees['nom'],
                'service' => $donnees['service'] ?? null,
            ]);
            $this->ajouterLits($chambre, $donnees['lits']);
            $this->fixerPrix($chambre, $donnees['prix'] ?? null);

            return $chambre;
        });
    }

    /**
     * Modifie la chambre. Fermée, ses lits ne reçoivent plus personne : on
     * ne la ferme pas avec un patient couché dedans.
     *
     * @param  array{nom?: string, service?: string|null, actif?: bool, prix?: int|null, ajouter_lits?: int}  $donnees
     */
    public function modifierChambre(Chambre $chambre, array $donnees): Chambre
    {
        return DB::transaction(function () use ($chambre, $donnees): Chambre {
            if (($donnees['actif'] ?? true) === false && $chambre->actif
                && Hospitalisation::where('statut', 'en_cours')->whereIn('lit_id', $chambre->lits()->select('id'))->exists()) {
                throw new Refus('Des patients sont couchés dans cette chambre : changez-les de lit avant de la fermer.');
            }
            $chambre->update(array_intersect_key($donnees, array_flip(['nom', 'service', 'actif'])));
            if (array_key_exists('prix', $donnees)) {
                $this->fixerPrix($chambre, $donnees['prix']);
            } elseif ($chambre->wasChanged('nom') && $chambre->tarif_id !== null) {
                // L'acte du tarif suit le nom de la chambre.
                Tarif::whereKey($chambre->tarif_id)->update(['nom' => "Nuit — {$chambre->nom}"]);
            }
            if (($donnees['ajouter_lits'] ?? 0) > 0) {
                $this->ajouterLits($chambre, (int) $donnees['ajouter_lits']);
            }

            return $chambre;
        });
    }

    public function ajouterLits(Chambre $chambre, int $nombre): void
    {
        $dernier = 0;
        foreach ($chambre->lits()->get() as $l) {
            $dernier = max($dernier, (int) $l->numero);
        }
        for ($i = 1; $i <= $nombre; $i++) {
            Lit::create(['chambre_id' => $chambre->id, 'numero' => (string) ($dernier + $i)]);
        }
    }

    /** Le prix de la nuit : l'acte « Nuit — <chambre> » du tarif. */
    public function fixerPrix(Chambre $chambre, ?int $prix): void
    {
        $tarif = $chambre->tarif_id !== null ? Tarif::find($chambre->tarif_id) : null;
        if ($prix === null || $prix <= 0) {
            $tarif?->delete();
            $chambre->update(['tarif_id' => null]);
        } elseif ($tarif !== null) {
            $tarif->update(['nom' => "Nuit — {$chambre->nom}", 'prix' => $prix]);
        } else {
            $tarif = Tarif::create([
                'etablissement_id' => $chambre->etablissement_id,
                'nom' => "Nuit — {$chambre->nom}",
                'categorie' => 'hospitalisation',
                'prix' => $prix,
                'actif' => true,
            ]);
            $chambre->update(['tarif_id' => $tarif->id]);
        }
    }

    /**
     * Les lits occupés : id du lit => séjour.
     *
     * @return Collection<int, Hospitalisation>
     */
    public function occupation(Etablissement $etablissement): Collection
    {
        return Hospitalisation::with('dossier')->where('etablissement_id', $etablissement->id)
            ->where('statut', 'en_cours')->whereNotNull('lit_id')->get()
            ->keyBy(fn (Hospitalisation $h): int => (int) $h->lit_id);
    }

    public function admettre(Etablissement $etablissement, Dossier $dossier, ?Visite $visite, Lit $lit, string $motif, User $par): Hospitalisation
    {
        return DB::transaction(function () use ($etablissement, $dossier, $visite, $lit, $motif, $par): Hospitalisation {
            if (Hospitalisation::where('dossier_id', $dossier->id)->where('statut', 'en_cours')->exists()) {
                throw new Refus('Ce patient est déjà hospitalisé.');
            }
            $this->litLibre($lit);

            return Hospitalisation::create([
                'etablissement_id' => $etablissement->id,
                'dossier_id' => $dossier->id,
                'visite_id' => $visite?->id,
                'lit_id' => $lit->id,
                'medecin_id' => $par->id,
                'motif' => $motif,
                'statut' => 'en_cours',
                'entree_at' => now(),
            ]);
        });
    }

    public function transferer(Hospitalisation $h, Lit $lit): Hospitalisation
    {
        $this->enCours($h);

        return DB::transaction(function () use ($h, $lit): Hospitalisation {
            $this->litLibre($lit);
            $h->update(['lit_id' => $lit->id]);

            return $h;
        });
    }

    /**
     * La sortie : les nuits passées s'ajoutent à la facture de la visite
     * d'admission si elle est encore ouverte, sinon à une facture du jour
     * (la visite ouverte du patient, ou une nouvelle). Rend ce qui est
     * facturé, null quand la chambre n'a pas de prix.
     *
     * @return array{visite_uuid: string, nuits: int, montant: int, nouvelle: bool}|null
     */
    public function sortir(Hospitalisation $h, string $mode, ?string $resume, User $par): ?array
    {
        $this->enCours($h);

        return DB::transaction(function () use ($h, $mode, $resume, $par): ?array {
            $h->update(['statut' => 'sortie', 'sortie_at' => now(), 'mode_sortie' => $mode, 'resume_sortie' => $resume]);
            $h->soins()->whereNull('arrete_at')->update(['arrete_at' => now()]);

            $tarif = $h->lit?->chambre->tarif;
            if ($tarif === null) {
                return null;
            }
            $visite = $h->visite_id !== null ? Visite::find($h->visite_id) : null;
            $nouvelle = $visite === null || $visite->statut !== 'ouverte';
            if ($nouvelle) {
                $dossier = $h->dossier;
                $visite = $this->caisse->visitePourTicket($h->etablissement, [
                    'dossier_id' => $dossier->id,
                    'personne_id' => $dossier->personne_id,
                    'nom' => $dossier->nom,
                    'telephone' => $dossier->telephone,
                ]);
            }
            $ligne = $this->caisse->ajouterLigne($visite, $tarif, null, null, $this->nuits($h), $par);

            return ['visite_uuid' => $visite->uuid, 'nuits' => $ligne->quantite, 'montant' => $ligne->montant, 'nouvelle' => $nouvelle];
        });
    }

    /**
     * Une admission faite par erreur (mauvais patient, mauvais lit) : le
     * jour même et avant tout soin donné, elle s'annule. Le lit se libère,
     * rien n'est facturé.
     */
    public function annulerAdmission(Hospitalisation $h): Hospitalisation
    {
        $this->enCours($h);
        $fuseau = $this->fuseau($h);
        if ($h->entree_at->copy()->setTimezone($fuseau)->toDateString() !== now()->setTimezone($fuseau)->toDateString()) {
            throw new Refus('L’admission date d’un autre jour : faites la sortie du patient.');
        }
        if (Administration::where('hospitalisation_id', $h->id)->where('statut', 'faite')->exists()) {
            throw new Refus('Des soins ont déjà été donnés : faites la sortie du patient.');
        }

        return DB::transaction(function () use ($h): Hospitalisation {
            $h->update(['statut' => 'annulee', 'sortie_at' => now()]);
            $h->soins()->whereNull('arrete_at')->update(['arrete_at' => now()]);

            return $h;
        });
    }

    /** Au moins une nuit ; une par changement de date ensuite. */
    public function nuits(Hospitalisation $h): int
    {
        $fin = $h->sortie_at ?? now();

        return max(1, (int) $h->entree_at->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay()));
    }

    /**
     * @param  array{produit: string, dose?: string|null, voie: string, horaires: list<string>, fin?: string|null, instructions?: string|null}  $donnees
     */
    public function prescrire(Hospitalisation $h, array $donnees, User $par): SoinPrescrit
    {
        $this->enCours($h);
        $horaires = array_values(array_unique($donnees['horaires']));
        sort($horaires);

        return SoinPrescrit::create([
            ...$donnees,
            'horaires' => $horaires,
            'hospitalisation_id' => $h->id,
            'prescripteur_id' => $par->id,
            'debut' => now()->setTimezone($this->fuseau($h))->toDateString(),
        ]);
    }

    /**
     * Le plan de soins d'un jour : chaque prise prévue, faite ou à faire.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(Hospitalisation $h, ?string $jour = null): array
    {
        $fuseau = $this->fuseau($h);
        $jour ??= now()->setTimezone($fuseau)->toDateString();
        $faites = Administration::with('soignant')->where('hospitalisation_id', $h->id)
            ->whereBetween('prevue_at', [
                Carbon::parse("{$jour} 00:00", $fuseau)->utc(),
                Carbon::parse("{$jour} 23:59:59", $fuseau)->utc(),
            ])->get()
            ->keyBy(fn (Administration $a): string => "{$a->soin_id}@{$a->prevue_at->utc()->format('Y-m-d H:i')}");

        $prises = [];
        foreach ($h->soins()->with('prescripteur')->get() as $soin) {
            if ($soin->debut->toDateString() > $jour || ($soin->fin !== null && $soin->fin->toDateString() < $jour)) {
                continue;
            }
            foreach ($soin->horaires as $heure) {
                $prevue = Carbon::parse("{$jour} {$heure}", $fuseau)->utc();
                $a = $faites->get("{$soin->id}@{$prevue->format('Y-m-d H:i')}");
                if ($a === null && $soin->arrete_at !== null && $soin->arrete_at->lte($prevue)) {
                    continue;
                }
                $prises[] = [
                    'soin_uuid' => $soin->uuid,
                    'produit' => $soin->produit,
                    'dose' => $soin->dose,
                    'voie' => $soin->voie,
                    'instructions' => $soin->instructions,
                    'prevue_at' => $prevue->toIso8601String(),
                    'heure' => $heure,
                    'statut' => $a->statut ?? 'a_faire',
                    'en_retard' => $a === null && $prevue->copy()->addMinutes(self::RETARD_MIN)->isPast(),
                    'par' => $a?->soignant?->getAttribute('name'),
                    'faite_at' => $a?->faite_at->toIso8601String(),
                    'commentaire' => $a?->commentaire,
                    'corrigee' => ($a->corrections ?? []) !== [],
                    'corrigeable' => $a !== null && $a->faite_at->copy()->addHours(self::CORRECTION_HEURES)->isFuture(),
                ];
            }
        }
        usort($prises, fn (array $x, array $y): int => strcmp((string) $x['prevue_at'], (string) $y['prevue_at']));

        return $prises;
    }

    /**
     * Une prise au lit. « faite » exige le bracelet du patient : l'app refuse
     * le soin si ce n'est pas le bon.
     */
    public function administrer(Hospitalisation $h, SoinPrescrit $soin, Carbon $prevue, string $statut, ?string $bracelet, ?string $commentaire, User $par): Administration
    {
        $this->enCours($h);
        if ($soin->hospitalisation_id !== $h->id) {
            throw new Refus('Ce soin n’est pas prescrit à ce patient.');
        }
        $heure = $prevue->copy()->setTimezone($this->fuseau($h))->format('H:i');
        if (! in_array($heure, $soin->horaires, true)) {
            throw new Refus('Cette prise n’est pas au plan de soins.');
        }
        if ($statut === 'faite' && $bracelet !== self::bracelet($h)) {
            throw new Refus('Ce bracelet n’est pas celui du patient : ne donnez pas ce soin.');
        }
        if (Administration::where('soin_id', $soin->id)->where('prevue_at', $prevue->copy()->utc())->exists()) {
            throw new Refus('Cette prise est déjà notée.');
        }

        return Administration::create([
            'soin_id' => $soin->id,
            'hospitalisation_id' => $h->id,
            'prevue_at' => $prevue->copy()->utc(),
            'statut' => $statut,
            'faite_at' => now(),
            'par' => $par->id,
            'bracelet_verifie' => $statut === 'faite',
            'commentaire' => $commentaire,
        ]);
    }

    /**
     * Corrige une prise notée par erreur (« donné » alors que le patient a
     * refusé, « non fait » alors qu'il l'a pris…), dans les heures qui
     * suivent et avec la raison. L'ancienne saisie reste écrite. Passer à
     * « faite » exige le bracelet, comme au lit.
     */
    public function corrigerPrise(Hospitalisation $h, SoinPrescrit $soin, Carbon $prevue, string $statut, string $motif, ?string $bracelet, User $par): Administration
    {
        $this->enCours($h);
        $a = Administration::where('hospitalisation_id', $h->id)->where('soin_id', $soin->id)
            ->where('prevue_at', $prevue->copy()->utc())->first();
        if ($a === null) {
            throw new Refus('Cette prise n’est pas encore notée.');
        }
        if ($a->faite_at->copy()->addHours(self::CORRECTION_HEURES)->isPast()) {
            throw new Refus('Cette prise est notée depuis plus de '.self::CORRECTION_HEURES.' heures : elle ne se corrige plus.');
        }
        if ($a->statut === $statut) {
            throw new Refus('La prise est déjà notée ainsi.');
        }
        if ($statut === 'faite' && $bracelet !== self::bracelet($h)) {
            throw new Refus('Ce bracelet n’est pas celui du patient : ne donnez pas ce soin.');
        }
        $a->update([
            'corrections' => [...($a->corrections ?? []), [
                'de' => $a->statut,
                'motif' => $motif,
                'par' => $par->getAttribute('name'),
                'le' => now()->toIso8601String(),
            ]],
            'statut' => $statut,
            'par' => $par->id,
            'bracelet_verifie' => $statut === 'faite',
            'commentaire' => $motif,
        ]);

        return $a;
    }

    public static function bracelet(Hospitalisation $h): string
    {
        return Qr::creer(self::QR, $h->uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function pourAffichage(Hospitalisation $h, bool $avecPlan = false): array
    {
        $h->loadMissing('dossier', 'lit.chambre', 'medecin', 'soins.prescripteur');

        return [
            'uuid' => $h->uuid,
            'statut' => $h->statut,
            'patient' => $h->dossier->nom,
            'dossier_uuid' => $h->dossier->uuid,
            'allergies' => $h->dossier->allergies,
            'lit' => $h->lit?->libelle(),
            'lit_uuid' => $h->lit?->uuid,
            'motif' => $h->motif,
            'medecin' => $h->medecin?->getAttribute('name'),
            'entree_at' => $h->entree_at->toIso8601String(),
            'sortie_at' => $h->sortie_at?->toIso8601String(),
            'mode_sortie' => $h->mode_sortie,
            'resume_sortie' => $h->resume_sortie,
            'nuits' => $this->nuits($h),
            'annulable' => $h->statut === 'en_cours'
                && $h->entree_at->copy()->setTimezone($this->fuseau($h))->toDateString() === now()->setTimezone($this->fuseau($h))->toDateString(),
            'bracelet' => self::bracelet($h),
            'soins' => $h->soins->map(fn (SoinPrescrit $s): array => [
                'uuid' => $s->uuid,
                'produit' => $s->produit,
                'dose' => $s->dose,
                'voie' => $s->voie,
                'horaires' => $s->horaires,
                'instructions' => $s->instructions,
                'prescripteur' => $s->prescripteur?->getAttribute('name'),
                'debut' => $s->debut->toDateString(),
                'fin' => $s->fin?->toDateString(),
                'arrete' => $s->arrete_at !== null,
            ])->values(),
            ...($avecPlan ? ['plan' => $this->plan($h)] : []),
        ];
    }

    private function litLibre(Lit $lit): void
    {
        if (! $lit->actif || ! $lit->chambre->actif) {
            throw new Refus('Ce lit est fermé.');
        }
        if (Hospitalisation::where('lit_id', $lit->id)->where('statut', 'en_cours')->lockForUpdate()->exists()) {
            throw new Refus('Ce lit est occupé.');
        }
    }

    private function enCours(Hospitalisation $h): void
    {
        if ($h->statut !== 'en_cours') {
            throw new Refus($h->statut === 'annulee' ? 'Cette admission est annulée.' : 'Le patient est sorti.');
        }
    }

    private function fuseau(Hospitalisation $h): string
    {
        return $this->agendas->fuseau($h->etablissement);
    }
}
