<?php

declare(strict_types=1);

namespace App\Sante;

/**
 * Profil d'un établissement : son métier. Il fixe les modules actifs par
 * défaut (comme l'« activité » de Ngoni Caisse fixe les écrans de la caisse).
 */
enum Profil: string
{
    case CabinetMedical = 'cabinet_medical';
    case CentreSante = 'centre_sante';
    case Clinique = 'clinique';
    case Pharmacie = 'pharmacie';
    case DepotMedicaments = 'depot_medicaments';
    case Laboratoire = 'laboratoire';
    case Maternite = 'maternite';
    case CabinetDentaire = 'cabinet_dentaire';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::CabinetMedical => 'Cabinet médical',
            self::CentreSante => 'Centre de santé',
            self::Clinique => 'Clinique / hôpital',
            self::Pharmacie => 'Pharmacie',
            self::DepotMedicaments => 'Dépôt de médicaments',
            self::Laboratoire => 'Laboratoire',
            self::Maternite => 'Maternité',
            self::CabinetDentaire => 'Cabinet dentaire',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CabinetMedical => 'Consultations, rendez-vous et file d’attente.',
            self::CentreSante => 'CSCom, CSRéf : consultation, pharmacie, labo, maternité.',
            self::Clinique => 'Plusieurs services, hospitalisation, badges.',
            self::Pharmacie => 'Ordonnances, lots et péremption, grossistes.',
            self::DepotMedicaments => 'Vente de médicaments, stock et caisse.',
            self::Laboratoire => 'Demandes d’examens, résultats, rendez-vous.',
            self::Maternite => 'Suivi de grossesse, accouchement, vaccination.',
            self::CabinetDentaire => 'Soins dentaires, rendez-vous et file d’attente.',
        };
    }

    /**
     * @return list<Module>
     */
    public function modulesParDefaut(): array
    {
        return match ($this) {
            self::CabinetMedical, self::CabinetDentaire => [
                Module::File, Module::RendezVous, Module::Consultation, Module::Caisse,
            ],
            self::CentreSante => [
                Module::File, Module::RendezVous, Module::Consultation, Module::Caisse,
                Module::Pharmacie, Module::Labo, Module::Maternite,
            ],
            self::Clinique => Module::cases(),
            self::Pharmacie => [Module::File, Module::Caisse, Module::Pharmacie],
            self::DepotMedicaments => [Module::Caisse, Module::Pharmacie],
            self::Laboratoire => [Module::File, Module::RendezVous, Module::Labo, Module::Caisse],
            self::Maternite => [
                Module::File, Module::RendezVous, Module::Maternite, Module::Consultation, Module::Caisse,
            ],
        };
    }

    /**
     * Profil d'un établissement créé avant les profils (colonne `type`).
     */
    public static function depuisType(?string $type): self
    {
        return match ($type) {
            'pharmacie' => self::Pharmacie,
            'cabinet_pharmacie' => self::CentreSante,
            default => self::CabinetMedical,
        };
    }

    /**
     * Valeur de l'ancienne colonne `type`, encore lue par l'app 3.x.
     *
     * @param  list<string>  $modules
     */
    public static function typeLegacy(array $modules): string
    {
        $consultation = in_array(Module::Consultation->value, $modules, true);
        $pharmacie = in_array(Module::Pharmacie->value, $modules, true);

        return match (true) {
            $consultation && $pharmacie => 'cabinet_pharmacie',
            $pharmacie => 'pharmacie',
            default => 'cabinet',
        };
    }

    /**
     * @return array{code: string, label: string, description: string, modules: list<string>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'modules' => array_map(static fn (Module $m): string => $m->value, $this->modulesParDefaut()),
        ];
    }
}
