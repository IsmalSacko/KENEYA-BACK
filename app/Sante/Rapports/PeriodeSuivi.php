<?php

declare(strict_types=1);

namespace App\Sante\Rapports;

use App\Sante\Refus;
use Carbon\CarbonImmutable;
use Closure;

/**
 * La période du suivi d'un compte dans la Console Ngoni (contrat v1.6) : du …
 * au …, la période d'avant pour comparer, et les créneaux de la courbe (par
 * heure, par jour ou par mois). Heure de référence : UTC.
 */
final class PeriodeSuivi
{
    public const HEURE = 'heure';

    public const JOUR = 'jour';

    public const MOIS = 'mois';

    /** Une période perso tracée jour par jour jusqu'à 62 jours ; au-delà, par mois. */
    private const JOURS_PAR_JOUR = 62;

    /** `tout` : la courbe montre les 24 derniers mois au plus. */
    private const MOIS_AU_PLUS = 24;

    private const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    private function __construct(
        public readonly string $code,
        public readonly CarbonImmutable $du,
        public readonly CarbonImmutable $au,
        public readonly ?CarbonImmutable $avantDu,
        public readonly ?CarbonImmutable $avantAu,
        public readonly string $pas,
        private readonly ?CarbonImmutable $courbeDepuis = null,
    ) {}

    /**
     * @param  Closure(): CarbonImmutable  $debutDuCompte  la première activité du compte (sinon sa création), pour `tout`
     *
     * @throws Refus une période invalide (réponse 422)
     */
    public static function depuis(?string $code, ?string $du, ?string $au, CarbonImmutable $aujourdhui, Closure $debutDuCompte): self
    {
        $aujourdhui = $aujourdhui->utc()->startOfDay();

        switch ($code === null || $code === '' ? '30j' : $code) {
            case '30j':
                $debut = $aujourdhui->subDays(29);

                return new self('30j', $debut, $aujourdhui, $debut->subDays(30), $debut->subDay(), self::JOUR);
            case 'jour':
                return new self('jour', $aujourdhui, $aujourdhui, $aujourdhui->subDay(), $aujourdhui->subDay(), self::HEURE);
            case '7j':
                $debut = $aujourdhui->subDays(6);

                return new self('7j', $debut, $aujourdhui, $debut->subDays(7), $debut->subDay(), self::JOUR);
            case 'mois':
                $debut = $aujourdhui->startOfMonth();
                $avant = $debut->subMonthNoOverflow();
                // Le mois d'avant, du 1er au même jour, borné à sa fin (31 mars → 28 février).
                $avantAu = $avant->addDays(min($aujourdhui->day, $avant->daysInMonth) - 1);

                return new self('mois', $debut, $aujourdhui, $avant, $avantAu, self::JOUR);
            case 'tout':
                $debut = $debutDuCompte()->utc()->startOfDay();
                $debut = $debut->greaterThan($aujourdhui) ? $aujourdhui : $debut;
                $courbe = $aujourdhui->startOfMonth()->subMonthsNoOverflow(self::MOIS_AU_PLUS - 1);

                return new self('tout', $debut, $aujourdhui, null, null, self::MOIS, $courbe);
            case 'perso':
                return self::perso($du, $au, $aujourdhui);
            default:
                throw new Refus('Période inconnue : choisissez jour, 7j, mois, tout ou perso.');
        }
    }

    private static function perso(?string $du, ?string $au, CarbonImmutable $aujourdhui): self
    {
        $debut = self::date($du);
        $fin = self::date($au);
        if ($debut === null || $fin === null) {
            throw new Refus('Indiquez la date de début et la date de fin de la période (du et au, au format AAAA-MM-JJ).');
        }
        if ($fin->greaterThan($aujourdhui)) {
            throw new Refus('La date de fin ne peut pas être après aujourd’hui.');
        }
        if ($debut->greaterThan($fin)) {
            throw new Refus('La date de début doit être avant la date de fin.');
        }
        if ($debut->addYearsNoOverflow(3)->lessThan($fin)) {
            throw new Refus('Choisissez une période de 3 ans au plus.');
        }
        $jours = (int) $debut->diffInDays($fin) + 1;
        $pas = match (true) {
            $jours === 1 => self::HEURE,
            $jours <= self::JOURS_PAR_JOUR => self::JOUR,
            default => self::MOIS,
        };

        return new self('perso', $debut, $fin, $debut->subDays($jours), $debut->subDay(), $pas);
    }

    private static function date(?string $texte): ?CarbonImmutable
    {
        if ($texte === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $texte) !== 1) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $texte, 'UTC');

        // « 2026-02-30 » n'est pas une date : Carbon la reporterait au 2 mars.
        return $date !== null && $date->format('Y-m-d') === $texte ? $date : null;
    }

    public function libelle(): string
    {
        return match ($this->code) {
            'jour' => 'Aujourd’hui',
            '7j' => '7 derniers jours',
            'mois' => 'Ce mois',
            'tout' => 'Depuis le début',
            'perso' => 'Du '.$this->du->format('d/m/Y').' au '.$this->au->format('d/m/Y'),
            default => '30 derniers jours',
        };
    }

    /** Le début de la période (00:00:00 du premier jour). */
    public function debut(): CarbonImmutable
    {
        return $this->du->startOfDay();
    }

    /** La fin de la période (23:59:59 du dernier jour). */
    public function fin(): CarbonImmutable
    {
        return $this->au->endOfDay();
    }

    public function aUnAvant(): bool
    {
        return $this->avantDu !== null && $this->avantAu !== null;
    }

    /** Le premier instant que la courbe montre (les 24 derniers mois au plus pour `tout`). */
    public function debutCourbe(): CarbonImmutable
    {
        return $this->courbeDepuis !== null ? $this->courbeDepuis->max($this->debut()) : $this->debut();
    }

    /**
     * @return array{code: string, libelle: string, du: string, au: string, avant_du: string|null, avant_au: string|null}
     */
    public function pourContrat(): array
    {
        return [
            'code' => $this->code,
            'libelle' => $this->libelle(),
            'du' => $this->du->toDateString(),
            'au' => $this->au->toDateString(),
            'avant_du' => $this->avantDu?->toDateString(),
            'avant_au' => $this->avantAu?->toDateString(),
        ];
    }

    /**
     * Les créneaux de la courbe, dans l'ordre : `cle` (AAAA-MM-JJ HH, AAAA-MM-JJ
     * ou AAAA-MM, pour ranger les données), `date` (le début du créneau, AAAA-MM-JJ
     * ou AAAA-MM-JJTHH:00) et `libelle` (« 14 h », « 05/11 », « nov. 2026 »).
     *
     * @return list<array{cle: string, date: string, libelle: string}>
     */
    public function creneaux(): array
    {
        $creneaux = [];
        if ($this->pas === self::HEURE) {
            for ($h = 0; $h < 24; $h++) {
                $instant = $this->du->setTime($h, 0);
                $creneaux[] = ['cle' => $instant->format('Y-m-d H'), 'date' => $instant->format('Y-m-d\TH:00'), 'libelle' => "{$h} h"];
            }

            return $creneaux;
        }
        if ($this->pas === self::JOUR) {
            for ($j = $this->du; $j->lessThanOrEqualTo($this->au); $j = $j->addDay()) {
                $creneaux[] = ['cle' => $j->toDateString(), 'date' => $j->toDateString(), 'libelle' => $j->format('d/m')];
            }

            return $creneaux;
        }
        for ($m = $this->debutCourbe()->startOfMonth(); $m->lessThanOrEqualTo($this->au); $m = $m->addMonthNoOverflow()) {
            $creneaux[] = [
                'cle' => $m->format('Y-m'),
                'date' => $m->toDateString(),
                'libelle' => self::MOIS_COURTS[$m->month - 1].' '.$m->year,
            ];
        }

        return $creneaux;
    }

    /** Le créneau où tombe un instant (ou un jour AAAA-MM-JJ), selon le pas. */
    public function cleDe(CarbonImmutable $instant): string
    {
        return match ($this->pas) {
            self::HEURE => $instant->format('Y-m-d H'),
            self::JOUR => $instant->toDateString(),
            default => $instant->format('Y-m'),
        };
    }
}
