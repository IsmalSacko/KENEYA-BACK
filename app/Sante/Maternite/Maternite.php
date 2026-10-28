<?php

declare(strict_types=1);

namespace App\Sante\Maternite;

use App\Models\Accouchement;
use App\Models\Cpn;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Grossesse;
use App\Models\Naissance;
use App\Models\User;
use App\Models\Vaccination;
use App\Sante\Refus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La maternité : grossesse et CPN, accouchement, nouveau-né, carnet de
 * vaccination de l'enfant.
 */
final class Maternite
{
    /** Terme : DDR + 280 jours (règle de Naegele). */
    public const JOURS_TERME = 280;

    /** Comment finit une grossesse sans accouchement ici. */
    public const ISSUES = ['fausse_couche', 'ivg', 'geu', 'transferee', 'perdue_de_vue'];

    /**
     * Calendrier du PEV (Mali) : code, nom, âge en jours. À vérifier avec le
     * district sanitaire : il change avec les campagnes nationales.
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    public const CALENDRIER = [
        ['BCG', 'BCG', 0],
        ['VPO0', 'Polio oral 0', 0],
        ['HEPB0', 'Hépatite B naissance', 0],
        ['PENTA1', 'Pentavalent 1', 42],
        ['VPO1', 'Polio oral 1', 42],
        ['PCV1', 'Pneumocoque 1', 42],
        ['ROTA1', 'Rotavirus 1', 42],
        ['PENTA2', 'Pentavalent 2', 70],
        ['VPO2', 'Polio oral 2', 70],
        ['PCV2', 'Pneumocoque 2', 70],
        ['ROTA2', 'Rotavirus 2', 70],
        ['PENTA3', 'Pentavalent 3', 98],
        ['VPO3', 'Polio oral 3', 98],
        ['PCV3', 'Pneumocoque 3', 98],
        ['VPI', 'Polio injectable', 98],
        ['RR1', 'Rougeole-Rubéole 1', 270],
        ['VAA', 'Fièvre jaune', 270],
        ['MENA', 'Méningite A', 270],
        ['RR2', 'Rougeole-Rubéole 2', 450],
    ];

    /**
     * @param  array{ddr: string, gestite?: int|null, parite?: int|null, risques?: string|null}  $donnees
     */
    public function declarer(Etablissement $etablissement, Dossier $mere, array $donnees): Grossesse
    {
        if (Grossesse::where('dossier_id', $mere->id)->where('statut', 'en_cours')->exists()) {
            throw new Refus('Une grossesse est déjà en cours dans ce dossier.');
        }
        $ddr = Carbon::parse($donnees['ddr']);
        if ($mere->sexe === null) {
            $mere->update(['sexe' => 'F']);
        }

        return Grossesse::create([
            'etablissement_id' => $etablissement->id,
            'dossier_id' => $mere->id,
            'ddr' => $ddr->toDateString(),
            'dpa' => $ddr->copy()->addDays(self::JOURS_TERME)->toDateString(),
            'gestite' => $donnees['gestite'] ?? null,
            'parite' => $donnees['parite'] ?? null,
            'risques' => $donnees['risques'] ?? null,
            'statut' => 'en_cours',
        ]);
    }

    /**
     * Corrige la DDR (le terme suit), la gestité, la parité, les risques.
     *
     * @param  array{ddr?: string, gestite?: int|null, parite?: int|null, risques?: string|null}  $donnees
     */
    public function modifier(Grossesse $grossesse, array $donnees): Grossesse
    {
        $this->enCours($grossesse);
        if (isset($donnees['ddr'])) {
            $ddr = Carbon::parse($donnees['ddr']);
            $donnees['ddr'] = $ddr->toDateString();
            $donnees['dpa'] = $ddr->copy()->addDays(self::JOURS_TERME)->toDateString();
        }
        $grossesse->update($donnees);

        return $grossesse;
    }

    /**
     * La grossesse finie sans accouchement ici : fausse couche, IVG, GEU,
     * suivie ailleurs, perdue de vue. Une nouvelle peut être déclarée.
     */
    public function terminer(Grossesse $grossesse, string $issue, string $le): Grossesse
    {
        $this->enCours($grossesse);
        if (! in_array($issue, self::ISSUES, true)) {
            throw new Refus('Issue inconnue.');
        }
        $grossesse->update(['statut' => 'terminee', 'issue' => $issue, 'terminee_at' => Carbon::parse($le)]);

        return $grossesse;
    }

    /**
     * Corrige la dernière CPN (une erreur de saisie), tant que la grossesse
     * est en cours.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function modifierCpn(Grossesse $grossesse, Cpn $cpn, array $donnees): Cpn
    {
        $this->enCours($grossesse);
        if ($grossesse->cpns()->max('numero') !== $cpn->numero) {
            throw new Refus('Seule la dernière CPN se corrige.');
        }
        $cpn->update($donnees);

        return $cpn;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function cpn(Grossesse $grossesse, array $donnees, User $par): Cpn
    {
        $this->enCours($grossesse);

        return Cpn::create([
            ...$donnees,
            'grossesse_id' => $grossesse->id,
            'etablissement_id' => $par->etablissement_id,
            'praticien_id' => $par->id,
            'numero' => $grossesse->cpns()->count() + 1,
            'semaines' => $grossesse->semaines(),
        ]);
    }

    /**
     * L'accouchement : chaque enfant né vivant reçoit son dossier, rattaché à
     * celui de sa mère.
     *
     * @param  array{date_heure: string, mode: string, observations?: string|null}  $donnees
     * @param  list<array{sexe: string, vivant?: bool, poids_g?: int|null, taille_cm?: int|null, apgar1?: int|null, apgar5?: int|null, prenom?: string|null}>  $enfants
     */
    public function accoucher(Grossesse $grossesse, array $donnees, array $enfants, User $par): Accouchement
    {
        $this->enCours($grossesse);
        if ($enfants === []) {
            throw new Refus('Indiquez au moins un enfant.');
        }

        return DB::transaction(function () use ($grossesse, $donnees, $enfants, $par): Accouchement {
            $mere = $grossesse->dossier;
            $quand = Carbon::parse($donnees['date_heure']);
            $accouchement = Accouchement::create([
                'grossesse_id' => $grossesse->id,
                'etablissement_id' => $par->etablissement_id,
                'praticien_id' => $par->id,
                'date_heure' => $quand,
                'mode' => $donnees['mode'],
                'observations' => $donnees['observations'] ?? null,
            ]);
            foreach ($enfants as $i => $e) {
                $vivant = $e['vivant'] ?? true;
                $dossier = null;
                if ($vivant) {
                    $prenom = trim((string) ($e['prenom'] ?? ''));
                    $dossier = Dossier::create([
                        'etablissement_id' => $par->etablissement_id,
                        'responsable_id' => $mere->id,
                        'nom' => $prenom !== '' ? $prenom : 'Bébé '.(count($enfants) > 1 ? ($i + 1).' ' : '').'de '.$mere->nom,
                        'telephone' => $mere->telephone,
                        'date_naissance' => $quand->toDateString(),
                        'sexe' => $e['sexe'],
                    ]);
                }
                Naissance::create([
                    'accouchement_id' => $accouchement->id,
                    'dossier_id' => $dossier?->id,
                    'sexe' => $e['sexe'],
                    'vivant' => $vivant,
                    'poids_g' => $e['poids_g'] ?? null,
                    'taille_cm' => $e['taille_cm'] ?? null,
                    'apgar1' => $e['apgar1'] ?? null,
                    'apgar5' => $e['apgar5'] ?? null,
                ]);
            }
            $grossesse->update(['statut' => 'accouchee', 'terminee_at' => $quand]);

            return $accouchement;
        });
    }

    public function vacciner(Dossier $enfant, string $vaccin, string $le, ?string $lot, User $par): Vaccination
    {
        if (! in_array($vaccin, array_column(self::CALENDRIER, 0), true)) {
            throw new Refus('Vaccin inconnu du calendrier.');
        }

        $naissance = $enfant->date_naissance;
        if ($naissance !== null && Carbon::parse($le)->lt($naissance->copy()->startOfDay())) {
            throw new Refus('La date est avant la naissance de l’enfant.');
        }

        return Vaccination::updateOrCreate(
            ['dossier_id' => $enfant->id, 'vaccin' => $vaccin],
            ['etablissement_id' => $par->etablissement_id, 'fait_par' => $par->id, 'fait_le' => $le, 'lot' => $lot],
        );
    }

    /** Un vaccin noté par erreur : seul l'établissement qui l'a noté le retire. */
    public function annulerVaccin(Dossier $enfant, string $vaccin, Etablissement $etablissement): void
    {
        $v = Vaccination::where('dossier_id', $enfant->id)->where('vaccin', $vaccin)->first();
        if ($v === null) {
            throw new Refus('Ce vaccin n’est pas noté.');
        }
        if ($v->etablissement_id !== $etablissement->id) {
            throw new Refus('Ce vaccin a été noté par un autre établissement.');
        }
        $v->delete();
    }

    /**
     * Le carnet : chaque vaccin du calendrier, fait ou à faire, et sa date.
     *
     * @return list<array{code: string, nom: string, prevu_le: string|null, fait_le: string|null, lieu: string|null, en_retard: bool}>
     */
    public function carnet(Dossier $enfant): array
    {
        $faits = Vaccination::with('etablissement')->where('dossier_id', $enfant->id)->get()->keyBy('vaccin');
        $naissance = $enfant->date_naissance;
        $carnet = [];
        foreach (self::CALENDRIER as [$code, $nom, $jours]) {
            $fait = $faits->get($code);
            $prevu = $naissance?->copy()->addDays($jours);
            $carnet[] = [
                'code' => $code,
                'nom' => $nom,
                'prevu_le' => $prevu?->toDateString(),
                'fait_le' => $fait?->fait_le->toDateString(),
                'lieu' => $fait?->etablissement->nom,
                // Une semaine de marge avant de parler de retard.
                'en_retard' => $fait === null && $prevu !== null && $prevu->copy()->addDays(7)->isPast(),
            ];
        }

        return $carnet;
    }

    /**
     * @return array<string, mixed>
     */
    public function pourAffichage(Grossesse $g): array
    {
        $g->loadMissing('dossier', 'cpns.praticien', 'cpns.etablissement', 'accouchement.naissances.dossier');
        $derniere = $g->cpns->last();

        return [
            'uuid' => $g->uuid,
            'statut' => $g->statut,
            'issue' => $g->issue,
            'terminee_at' => $g->terminee_at?->toDateString(),
            'patiente' => $g->dossier->nom,
            'dossier_uuid' => $g->dossier->uuid,
            'ddr' => $g->ddr->toDateString(),
            'dpa' => $g->dpa->toDateString(),
            'semaines' => $g->statut === 'en_cours' ? $g->semaines() : null,
            'gestite' => $g->gestite,
            'parite' => $g->parite,
            'risques' => $g->risques,
            'prochain_rdv' => $derniere?->prochain_rdv?->toDateString(),
            'cpn' => $g->cpns->map(fn (Cpn $c): array => [
                'uuid' => $c->uuid,
                'numero' => $c->numero,
                'date' => $c->created_at?->toIso8601String(),
                'semaines' => $c->semaines,
                'etablissement' => $c->etablissement->nom,
                'praticien' => $c->praticien?->getAttribute('name'),
                'poids' => $c->poids,
                'ta_sys' => $c->ta_sys,
                'ta_dia' => $c->ta_dia,
                'hauteur_uterine' => $c->hauteur_uterine,
                'bcf' => $c->bcf,
                'presentation' => $c->presentation,
                'oedemes' => $c->oedemes,
                'albuminurie' => $c->albuminurie,
                'tpi' => $c->tpi,
                'vat' => $c->vat,
                'fer' => $c->fer,
                'moustiquaire' => $c->moustiquaire,
                'observations' => $c->observations,
                'prochain_rdv' => $c->prochain_rdv?->toDateString(),
            ])->values(),
            'accouchement' => $g->accouchement === null ? null : [
                'date_heure' => $g->accouchement->date_heure->toIso8601String(),
                'mode' => $g->accouchement->mode,
                'observations' => $g->accouchement->observations,
                'enfants' => $g->accouchement->naissances->map(fn (Naissance $n): array => [
                    'sexe' => $n->sexe,
                    'vivant' => $n->vivant,
                    'poids_g' => $n->poids_g,
                    'taille_cm' => $n->taille_cm,
                    'apgar1' => $n->apgar1,
                    'apgar5' => $n->apgar5,
                    'dossier_uuid' => $n->dossier?->uuid,
                    'nom' => $n->dossier?->nom,
                ])->values(),
            ],
        ];
    }

    private function enCours(Grossesse $g): void
    {
        if ($g->statut !== 'en_cours') {
            throw new Refus('Cette grossesse est terminée.');
        }
    }
}
