<?php

declare(strict_types=1);

namespace App\Sante\Rapports;

use App\Models\Etablissement;
use App\Models\RendezVous;
use App\Sante\Devise;
use App\Sante\Module;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * L'activité d'un établissement vue par la Console Ngoni (contrat v1.5 et
 * v1.6) : indicateurs, courbe de l'argent encaissé, actes et produits les
 * plus faits, sur la période choisie. Mêmes règles que le rapport mensuel
 * et la caisse : un paiement remboursé ne compte pas, une facture annulée
 * non plus. La console lit hors connexion : chaque requête filtre elle-même
 * sur l'établissement.
 */
final class SuiviConsole
{
    /** Ce qui fait d'un établissement un lieu de soins (et non une officine seule). */
    private const MODULES_SOINS = ['consultation', 'rdv', 'labo', 'maternite', 'hospitalisation'];

    /**
     * Ce qui se passe dans l'établissement, du plus parlant au moins parlant :
     * table, colonne de l'instant, et ce que la console en dit.
     */
    private const ACTIVITES = [
        ['encaissements', 'created_at', 'Encaissement'],
        ['visites', 'ouverte_at', 'Visite ou vente'],
        ['examens_cliniques', 'created_at', 'Consultation'],
        ['tickets', 'created_at', 'Ticket de file d’attente'],
        ['rendez_vous', 'created_at', 'Rendez-vous'],
        ['demandes_examen', 'created_at', 'Examen de laboratoire'],
        ['hospitalisations', 'created_at', 'Hospitalisation'],
        ['journal_audits', 'created_at', 'Action d’un membre'],
    ];

    public function __construct(private readonly RapportMensuel $rapport) {}

    /**
     * La période demandée par la console ; une période invalide lève un refus (422).
     */
    public function periode(Etablissement $e, ?string $code, ?string $du, ?string $au): PeriodeSuivi
    {
        return PeriodeSuivi::depuis($code, $du, $au, CarbonImmutable::now('UTC'), function () use ($e): CarbonImmutable {
            $premiere = $this->activite($e, 'MIN')[0];
            $cree = $e->getAttribute('created_at');

            return $premiere ?? ($cree instanceof \DateTimeInterface ? CarbonImmutable::instance($cree) : CarbonImmutable::now('UTC'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function pour(Etablissement $e, PeriodeSuivi $p): array
    {
        // Un établissement d'avant les modules (sans liste) : tout est montré.
        $modules = $e->getAttribute('modules');
        $modules = is_array($modules) && $modules !== [] ? array_values(array_filter($modules, is_string(...))) : Module::values();
        $soins = array_intersect(self::MODULES_SOINS, $modules) !== [];
        $pharmacie = in_array(Module::Pharmacie->value, $modules, true);
        $devise = $e->devise;

        $indicateurs = [];
        $indicateurs[] = $this->mesure('encaisse', 'Argent encaissé', 'montant', $p, fn (CarbonImmutable $d, CarbonImmutable $f): int => $this->montant($e, $this->encaisse($e, $d, $f)), $devise);

        if ($soins) {
            $frequentation = fn (CarbonImmutable $d, CarbonImmutable $f): array => $this->rapport->frequentation($e->id, Carbon::instance($d), Carbon::instance($f));
            $ici = $frequentation($p->debut(), $p->fin());
            $avant = $p->aUnAvant() ? $frequentation($this->avantDebut($p), $this->avantFin($p)) : null;
            $consultations = DB::table('examens_cliniques')->where('etablissement_id', $e->id)
                ->whereBetween('created_at', [$p->debut(), $p->fin()])->count();
            $indicateurs[] = $this->valeur('visites', 'Visites de patients', 'nombre', $ici['visites'], $avant['visites'] ?? null, note: $this->pluriel($consultations, 'consultation'));
            $indicateurs[] = $this->valeur('patients', 'Patients reçus', 'nombre', $ici['patients'], $avant['patients'] ?? null);
            $indicateurs[] = $this->valeur('nouveaux_patients', 'Nouveaux patients', 'nombre', $ici['nouveaux_patients'], $avant['nouveaux_patients'] ?? null);
        }

        if (in_array(Module::RendezVous->value, $modules, true)) {
            $rdv = fn (CarbonImmutable $d, CarbonImmutable $f): Builder => DB::table('rendez_vous')->where('etablissement_id', $e->id)
                ->whereNull('deleted_at')->whereIn('statut', RendezVous::OCCUPE)
                ->whereRaw('COALESCE(debut, date_souhaitee) BETWEEN ? AND ?', [$d->toDateTimeString(), $f->toDateTimeString()]);
            $honores = $rdv($p->debut(), $p->fin())->where('statut', 'honore')->count();
            $indicateurs[] = $this->mesure('rendez_vous', 'Rendez-vous', 'nombre', $p, fn (CarbonImmutable $d, CarbonImmutable $f): int => $rdv($d, $f)->count(),
                note: $honores > 0 ? $this->pluriel($honores, 'patient venu', 'patients venus') : null);
        }

        if ($pharmacie) {
            $ventes = $this->ventesComptoir($e, $p->debut(), $p->fin());
            $avant = $p->aUnAvant() ? $this->ventesComptoir($e, $this->avantDebut($p), $this->avantFin($p)) : null;
            $indicateurs[] = $this->valeur('ventes_pharmacie', 'Ventes de la pharmacie', 'montant', $this->montant($e, $ventes['total']),
                $avant !== null ? $this->montant($e, $avant['total']) : null, $devise, $this->pluriel($ventes['nombre'], 'vente'));
        }

        if (in_array(Module::Hospitalisation->value, $modules, true)) {
            $indicateurs[] = $this->valeur('hospitalises', 'Patients hospitalisés en ce moment', 'nombre',
                DB::table('hospitalisations')->where('etablissement_id', $e->id)->where('statut', 'en_cours')->count());
        }

        [$derniere, $quoi] = $this->activite($e, 'MAX');
        $indicateurs[] = $this->valeur('derniere_activite', 'Dernière activité dans l’application', 'instant', $derniere?->toIso8601String(), note: $quoi);

        $tableaux = [];
        if ($soins) {
            $tableaux[] = $this->tableau($e, 'Actes les plus faits', 'Acte', $this->lignesFacture($e, $p)->whereNull('visite_lignes.medicament_id')
                ->where('visites.nature', 'visite')->groupBy('visite_lignes.libelle')->selectRaw('visite_lignes.libelle as nom'));
        }
        if ($pharmacie) {
            $tableaux[] = $this->tableau($e, 'Produits les plus vendus', 'Produit', $this->lignesFacture($e, $p)
                ->join('medicaments', 'medicaments.id', '=', 'visite_lignes.medicament_id')
                ->groupBy('medicaments.id', 'medicaments.nom')->selectRaw('medicaments.nom as nom'));
        }

        return [
            'periode' => $p->pourContrat(),
            'indicateurs' => $indicateurs,
            'courbe' => [
                'titre' => 'Argent encaissé',
                'devise' => $devise,
                'points' => $this->courbe($e, $p),
            ],
            'tableaux' => $tableaux,
        ];
    }

    /**
     * L'argent encaissé, créneau par créneau ; `ventes` : le nombre de paiements.
     * Une journée seule se range par heure en PHP ; sinon, la base additionne
     * par jour et les mois se font en PHP.
     *
     * @return list<array{date: string, libelle: string, valeur: int, ventes: int}>
     */
    private function courbe(Etablissement $e, PeriodeSuivi $p): array
    {
        $paiements = $this->paiements($e, $p->debutCourbe(), $p->fin());
        $sommes = [];
        if ($p->pas === PeriodeSuivi::HEURE) {
            foreach ($paiements->get(['encaissements.created_at as le', 'encaissements.montant as montant']) as $r) {
                $cle = $p->cleDe(CarbonImmutable::parse(self::texte($r, 'le'), 'UTC'));
                $sommes[$cle] = ['total' => ($sommes[$cle]['total'] ?? 0) + self::entier($r, 'montant'), 'nombre' => ($sommes[$cle]['nombre'] ?? 0) + 1];
            }
        } else {
            $jours = $paiements->groupByRaw('DATE(encaissements.created_at)')
                ->selectRaw('DATE(encaissements.created_at) as jour, SUM(encaissements.montant) as total, COUNT(*) as nombre')->get();
            foreach ($jours as $r) {
                $cle = $p->cleDe(CarbonImmutable::parse(self::texte($r, 'jour'), 'UTC'));
                $sommes[$cle] = ['total' => ($sommes[$cle]['total'] ?? 0) + self::entier($r, 'total'), 'nombre' => ($sommes[$cle]['nombre'] ?? 0) + self::entier($r, 'nombre')];
            }
        }

        return array_map(fn (array $c): array => [
            'date' => $c['date'],
            'libelle' => $c['libelle'],
            'valeur' => $this->montant($e, $sommes[$c['cle']]['total'] ?? 0),
            'ventes' => $sommes[$c['cle']]['nombre'] ?? 0,
        ], $p->creneaux());
    }

    /** Les paiements reçus (non remboursés), au comptoir comme sur la facture d'un patient. */
    private function paiements(Etablissement $e, CarbonImmutable $du, CarbonImmutable $au): Builder
    {
        return DB::table('encaissements')->join('visites', 'visites.id', '=', 'encaissements.visite_id')
            ->where('visites.etablissement_id', $e->id)->whereNull('encaissements.rembourse_at')
            ->whereBetween('encaissements.created_at', [$du, $au]);
    }

    private function encaisse(Etablissement $e, CarbonImmutable $du, CarbonImmutable $au): int
    {
        // SUM rend une chaîne sous MySQL, un entier sous SQLite.
        return (int) $this->paiements($e, $du, $au)->sum('encaissements.montant');
    }

    /**
     * Les ventes au comptoir de la pharmacie (pas les factures des patients),
     * hors ventes annulées, comme le récapitulatif des ventes du jour.
     *
     * @return array{total: int, nombre: int}
     */
    private function ventesComptoir(Etablissement $e, CarbonImmutable $du, CarbonImmutable $au): array
    {
        $r = DB::table('visites')->where('etablissement_id', $e->id)->where('nature', 'vente')->where('statut', '!=', 'annulee')
            ->whereBetween('ouverte_at', [$du, $au])->selectRaw('SUM(total) as total, COUNT(*) as nombre')->first();

        return ['total' => $r !== null ? self::entier($r, 'total') : 0, 'nombre' => $r !== null ? self::entier($r, 'nombre') : 0];
    }

    /** Les lignes facturées sur la période, hors factures annulées. */
    private function lignesFacture(Etablissement $e, PeriodeSuivi $p): Builder
    {
        return DB::table('visite_lignes')->join('visites', 'visites.id', '=', 'visite_lignes.visite_id')
            ->where('visites.etablissement_id', $e->id)->where('visites.statut', '!=', 'annulee')
            ->whereBetween('visite_lignes.created_at', [$p->debut(), $p->fin()]);
    }

    /**
     * Les 10 premiers d'une requête groupée qui sélectionne `nom`.
     *
     * @return array<string, mixed>
     */
    private function tableau(Etablissement $e, string $titre, string $colonne, Builder $requete): array
    {
        $lignes = $requete->selectRaw('SUM(visite_lignes.quantite) as nombre, SUM(visite_lignes.montant) as montant_total')
            ->orderByDesc('nombre')->orderByDesc('montant_total')->limit(10)->get()
            ->map(fn (object $r): array => [self::texte($r, 'nom') ?? '—', self::entier($r, 'nombre'), $this->montant($e, self::entier($r, 'montant_total'))])
            ->values()->all();

        return [
            'titre' => $titre,
            'colonnes' => [
                ['libelle' => $colonne, 'type' => 'texte'],
                ['libelle' => 'Nombre', 'type' => 'nombre'],
                ['libelle' => 'Montant', 'type' => 'montant'],
            ],
            'devise' => $e->devise,
            'lignes' => $lignes,
        ];
    }

    /**
     * La première (MIN) ou la dernière (MAX) chose enregistrée dans
     * l'établissement, et ce que c'était.
     *
     * @return array{0: CarbonImmutable|null, 1: string|null}
     */
    private function activite(Etablissement $e, string $fonction): array
    {
        $trouve = null;
        $quoi = null;
        foreach (self::ACTIVITES as [$table, $colonne, $libelle]) {
            $requete = $table === 'encaissements'
                ? DB::table('encaissements')->join('visites', 'visites.id', '=', 'encaissements.visite_id')->where('visites.etablissement_id', $e->id)
                : DB::table($table)->where("{$table}.etablissement_id", $e->id);
            $valeur = $fonction === 'MIN' ? $requete->min("{$table}.{$colonne}") : $requete->max("{$table}.{$colonne}");
            if (! is_string($valeur) || $valeur === '') {
                continue;
            }
            $instant = CarbonImmutable::parse($valeur, 'UTC');
            if ($trouve === null || ($fonction === 'MIN' ? $instant->lessThan($trouve) : $instant->greaterThan($trouve))) {
                [$trouve, $quoi] = [$instant, $libelle];
            }
        }

        return [$trouve, $quoi];
    }

    /**
     * Une mesure sur la période, et sur la période d'avant s'il y en a une.
     *
     * @param  \Closure(CarbonImmutable, CarbonImmutable): int  $mesurer
     * @return array<string, mixed>
     */
    private function mesure(string $cle, string $libelle, string $type, PeriodeSuivi $p, \Closure $mesurer, ?string $devise = null, ?string $note = null): array
    {
        return $this->valeur($cle, $libelle, $type, $mesurer($p->debut(), $p->fin()),
            $p->aUnAvant() ? $mesurer($this->avantDebut($p), $this->avantFin($p)) : null, $devise, $note);
    }

    /**
     * @return array<string, mixed>
     */
    private function valeur(string $cle, string $libelle, string $type, int|string|null $valeur, ?int $avant = null, ?string $devise = null, ?string $note = null): array
    {
        return array_filter([
            'cle' => $cle,
            'libelle' => $libelle,
            'type' => $type,
            'valeur' => $valeur,
            'devise' => $devise,
            'avant' => $avant,
            'note' => $note,
        ], fn (mixed $v, string $k): bool => $v !== null || $k === 'valeur', ARRAY_FILTER_USE_BOTH);
    }

    private function avantDebut(PeriodeSuivi $p): CarbonImmutable
    {
        return ($p->avantDu ?? $p->du)->startOfDay();
    }

    private function avantFin(PeriodeSuivi $p): CarbonImmutable
    {
        return ($p->avantAu ?? $p->au)->endOfDay();
    }

    /**
     * La caisse compte dans la plus petite unité (pesewas pour le cedi) ; la
     * console reçoit des entiers dans l'unité de la devise.
     */
    private function montant(Etablissement $e, int $mineur): int
    {
        $decimales = Devise::decimales($e->devise);

        return $decimales === 0 ? $mineur : (int) round($mineur / (10 ** $decimales));
    }

    private function pluriel(int $n, string $un, ?string $plusieurs = null): string
    {
        return $n.' '.($n > 1 ? ($plusieurs ?? $un.'s') : $un);
    }

    private static function texte(object $ligne, string $champ): ?string
    {
        $v = get_object_vars($ligne)[$champ] ?? null;

        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }

    private static function entier(object $ligne, string $champ): int
    {
        $v = get_object_vars($ligne)[$champ] ?? null;

        return is_numeric($v) ? (int) $v : 0;
    }
}
