<?php

declare(strict_types=1);

namespace App\Sante\Rapports;

use App\Models\Etablissement;
use App\Models\Pays;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le rapport mensuel d'activité d'un centre, celui que le district demande :
 * fréquentation, consultations par âge et par sexe, diagnostics, paludisme,
 * maternité, vaccination, hospitalisation, recettes.
 */
final class RapportMensuel
{
    /** Tranches d'âge du rapport : jusqu'à (âge en années, exclu) => libellé. */
    private const AGES = [1 => '0-11 mois', 5 => '1-4 ans', 15 => '5-14 ans', 50 => '15-49 ans', 999 => '50 ans et +'];

    /**
     * @return array<string, mixed>
     */
    public function pour(Etablissement $etablissement, string $mois): array
    {
        $fuseau = Pays::whereKey($etablissement->pays_code)->value('fuseau');
        $fuseau = is_string($fuseau) ? $fuseau : 'Africa/Bamako';
        $debut = Carbon::parse($mois.'-01', $fuseau)->startOfMonth()->utc();
        $fin = Carbon::parse($mois.'-01', $fuseau)->endOfMonth()->utc();
        $e = $etablissement->id;
        $entre = static fn (string $table, string $colonne = 'created_at') => DB::table($table)->where("{$table}.etablissement_id", $e)
            ->whereBetween("{$table}.{$colonne}", [$debut, $fin]);

        // Consultations : par sexe et tranche d'âge, à la date de la consultation.
        $consultations = $entre('examens_cliniques')->leftJoin('dossiers', 'dossiers.id', '=', 'examens_cliniques.dossier_id')
            ->get(['examens_cliniques.created_at', 'examens_cliniques.diagnostic', 'examens_cliniques.cim10', 'dossiers.sexe', 'dossiers.date_naissance']);
        $compte = [];
        $diagnostics = [];
        foreach ($consultations as $c) {
            $sexe = self::texte($c, 'sexe') === 'F' ? 'F' : 'M';
            $cle = self::tranche(self::texte($c, 'date_naissance'), self::texte($c, 'created_at')).'|'.$sexe;
            $compte[$cle] = ($compte[$cle] ?? 0) + 1;
            $libelle = self::texte($c, 'diagnostic') ?? self::texte($c, 'cim10');
            if ($libelle !== null) {
                $cle = Str::lower(Str::ascii($libelle));
                $diagnostics[$cle] ??= ['diagnostic' => $libelle, 'cas' => 0];
                $diagnostics[$cle]['cas']++;
            }
        }
        $parAge = [];
        foreach ([...array_values(self::AGES), 'Âge inconnu'] as $t) {
            $parAge[$t] = ['F' => $compte["{$t}|F"] ?? 0, 'M' => $compte["{$t}|M"] ?? 0];
        }
        usort($diagnostics, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        // Paludisme : TDR et goutte épaisse rendus, et combien positifs.
        $palu = DB::table('demande_analyses')->join('demandes_examen', 'demandes_examen.id', '=', 'demande_analyses.demande_id')
            ->join('analyses', 'analyses.id', '=', 'demande_analyses.analyse_id')
            ->where('demandes_examen.etablissement_id', $e)->whereIn('analyses.code', ['TDR', 'GE'])
            ->whereNotNull('demande_analyses.resultat')->whereBetween('demande_analyses.saisi_at', [$debut, $fin])
            ->get(['analyses.code', 'demande_analyses.resultat']);
        $positif = static fn (object $r): bool => str_starts_with(Str::lower((string) self::texte($r, 'resultat')), 'positif');

        $cpn = $entre('cpn')->get(['numero', 'tpi']);
        $accouchements = $entre('accouchements', 'date_heure')->pluck('id');
        $naissances = DB::table('naissances')->whereIn('accouchement_id', $accouchements)->get(['vivant']);
        $hospit = DB::table('hospitalisations')->where('etablissement_id', $e);
        $encaisse = DB::table('encaissements')->join('visites', 'visites.id', '=', 'encaissements.visite_id')
            ->where('visites.etablissement_id', $e)->whereNull('encaissements.rembourse_at')
            ->whereBetween('encaissements.created_at', [$debut, $fin])
            ->groupBy('encaissements.moyen')->selectRaw('encaissements.moyen as moyen, SUM(encaissements.montant) as total')
            ->pluck('total', 'moyen')->map(fn (mixed $t): int => is_numeric($t) ? (int) $t : 0)->all();

        // L'activité jour par jour (visites des patients, pas les ventes du comptoir).
        $jours = $entre('visites', 'ouverte_at')->where('nature', 'visite')->where('statut', '!=', 'annulee')
            ->get(['ouverte_at'])->countBy(fn (object $v): string => Carbon::parse((string) self::texte($v, 'ouverte_at'))->setTimezone($fuseau)->toDateString());
        $parJour = [];
        for ($j = $debut->copy()->setTimezone($fuseau); $j->lte($fin->copy()->setTimezone($fuseau)); $j->addDay()) {
            $parJour[] = ['jour' => $j->toDateString(), 'visites' => $jours->get($j->toDateString(), 0)];
        }

        $precedent = Carbon::parse($mois.'-01', $fuseau)->subMonthNoOverflow();

        return [
            'mois' => $mois,
            'etablissement' => $etablissement->nom,
            'frequentation' => $this->frequentation($e, $debut, $fin),
            'par_jour' => $parJour,
            // Pour comparer : les mêmes chiffres au mois d'avant.
            'precedent' => [
                ...$this->frequentation($e, $precedent->copy()->startOfMonth()->utc(), $precedent->copy()->endOfMonth()->utc()),
                'consultations' => DB::table('examens_cliniques')->where('etablissement_id', $e)
                    ->whereBetween('created_at', [$precedent->copy()->startOfMonth()->utc(), $precedent->copy()->endOfMonth()->utc()])->count(),
                'encaisse' => (int) DB::table('encaissements')->join('visites', 'visites.id', '=', 'encaissements.visite_id')
                    ->where('visites.etablissement_id', $e)->whereNull('encaissements.rembourse_at')
                    ->whereBetween('encaissements.created_at', [$precedent->copy()->startOfMonth()->utc(), $precedent->copy()->endOfMonth()->utc()])
                    ->sum('encaissements.montant'),
            ],
            'consultations' => [
                'total' => $consultations->count(),
                'par_age' => $parAge,
            ],
            'diagnostics' => array_slice($diagnostics, 0, 10),
            'paludisme' => [
                'tdr' => $palu->where('code', 'TDR')->count(),
                'tdr_positifs' => $palu->where('code', 'TDR')->filter($positif)->count(),
                'ge' => $palu->where('code', 'GE')->count(),
                'ge_positives' => $palu->where('code', 'GE')->filter($positif)->count(),
            ],
            'maternite' => [
                'cpn' => $cpn->count(),
                'cpn1' => $cpn->filter(fn (object $c): bool => self::entier($c, 'numero') === 1)->count(),
                'cpn4_et_plus' => $cpn->filter(fn (object $c): bool => self::entier($c, 'numero') >= 4)->count(),
                'tpi' => $cpn->filter(fn (object $c): bool => (bool) self::entier($c, 'tpi'))->count(),
                'accouchements' => $accouchements->count(),
                'naissances_vivantes' => $naissances->filter(fn (object $n): bool => (bool) self::entier($n, 'vivant'))->count(),
                'mort_nes' => $naissances->reject(fn (object $n): bool => (bool) self::entier($n, 'vivant'))->count(),
            ],
            'vaccination' => $entre('vaccinations', 'fait_le')->groupBy('vaccin')->selectRaw('vaccin, COUNT(*) as doses')
                ->pluck('doses', 'vaccin')->map(fn (mixed $d): int => is_numeric($d) ? (int) $d : 0)->sortKeys()->all(),
            'laboratoire' => [
                'examens_valides' => $entre('demandes_examen', 'valide_at')->count(),
            ],
            'hospitalisation' => [
                'admissions' => (clone $hospit)->whereBetween('entree_at', [$debut, $fin])->count(),
                'sorties' => (clone $hospit)->whereBetween('sortie_at', [$debut, $fin])->count(),
                'deces' => (clone $hospit)->whereBetween('sortie_at', [$debut, $fin])->where('mode_sortie', 'decede')->count(),
            ],
            'finances' => [
                'facture' => (int) $entre('visites', 'ouverte_at')->where('statut', '!=', 'annulee')->sum('total'),
                'encaisse' => array_sum($encaisse),
                'par_moyen' => $encaisse,
            ],
        ];
    }

    /**
     * Visites, patients différents, et nouveaux (venus ici pour la première fois).
     *
     * @return array{visites: int, patients: int, nouveaux_patients: int}
     */
    private function frequentation(int $etablissement, Carbon $debut, Carbon $fin): array
    {
        $visites = DB::table('visites')->where('etablissement_id', $etablissement)->where('nature', 'visite')
            ->where('statut', '!=', 'annulee')->whereBetween('ouverte_at', [$debut, $fin]);
        $premieres = DB::table('visites')->where('etablissement_id', $etablissement)->where('nature', 'visite')
            ->whereNotNull('dossier_id')->groupBy('dossier_id')->selectRaw('dossier_id, MIN(ouverte_at) as premiere');

        return [
            'visites' => (clone $visites)->count(),
            'patients' => (clone $visites)->whereNotNull('dossier_id')->distinct()->count('dossier_id'),
            'nouveaux_patients' => DB::query()->fromSub($premieres, 'p')->whereBetween('premiere', [$debut, $fin])->count(),
        ];
    }

    private static function tranche(?string $naissance, ?string $le): string
    {
        if ($naissance === null || $le === null) {
            return 'Âge inconnu';
        }
        $age = Carbon::parse($naissance)->diffInYears(Carbon::parse($le));
        foreach (self::AGES as $jusqua => $libelle) {
            if ($age < $jusqua) {
                return $libelle;
            }
        }

        return '50 ans et +';
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
