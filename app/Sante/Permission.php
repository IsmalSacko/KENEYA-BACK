<?php

declare(strict_types=1);

namespace App\Sante;

use App\Enums\Role;

/**
 * Ce qu'un membre a le droit de faire. Un rôle est un ensemble de droits ;
 * l'admin de l'établissement peut ajuster celui de chaque rôle. Un droit
 * rattaché à un module ne vaut que si l'établissement a ce module.
 */
enum Permission: string
{
    // Socle (toujours disponibles)
    case PatientsVoir = 'patients.voir';
    case PatientsGerer = 'patients.gerer';
    case DossierLire = 'dossier.lire';
    case DossierEcrire = 'dossier.ecrire';
    case EquipeGerer = 'equipe.gerer';
    case EtablissementRegler = 'etablissement.regler';
    case StatsVoir = 'stats.voir';

    // File d'attente
    case FileEmettre = 'file.emettre';
    case FileAppeler = 'file.appeler';
    case FileGerer = 'file.gerer';

    // Rendez-vous
    case RdvVoir = 'rdv.voir';
    case RdvGerer = 'rdv.gerer';

    // Consultation
    case ConsultationFaire = 'consultation.faire';
    case OrdonnancePrescrire = 'ordonnance.prescrire';

    // Caisse
    case CaisseEncaisser = 'caisse.encaisser';
    case CaisseRapports = 'caisse.rapports';

    // Pharmacie
    case PharmacieDelivrer = 'pharmacie.delivrer';
    case StockGerer = 'stock.gerer';

    // Laboratoire
    case LaboSaisir = 'labo.saisir';
    case LaboValider = 'labo.valider';

    // Maternité
    case MaterniteSuivre = 'maternite.suivre';

    // Hospitalisation
    case HospitalisationGerer = 'hospitalisation.gerer';
    case SoinsAdministrer = 'soins.administrer';

    // Badges
    case BadgesGerer = 'badges.gerer';

    // Assurances
    case AssurancesGerer = 'assurances.gerer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /** Module dont dépend ce droit ; null pour le socle. */
    public function module(): ?Module
    {
        return match ($this) {
            self::FileEmettre, self::FileAppeler, self::FileGerer => Module::File,
            self::RdvVoir, self::RdvGerer => Module::RendezVous,
            self::ConsultationFaire, self::OrdonnancePrescrire => Module::Consultation,
            self::CaisseEncaisser, self::CaisseRapports => Module::Caisse,
            self::PharmacieDelivrer, self::StockGerer => Module::Pharmacie,
            self::LaboSaisir, self::LaboValider => Module::Labo,
            self::MaterniteSuivre => Module::Maternite,
            self::HospitalisationGerer, self::SoinsAdministrer => Module::Hospitalisation,
            self::BadgesGerer => Module::Badges,
            self::AssurancesGerer => Module::Assurances,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PatientsVoir => 'Voir les patients',
            self::PatientsGerer => 'Créer et modifier les patients',
            self::DossierLire => 'Lire les dossiers médicaux',
            self::DossierEcrire => 'Écrire dans les dossiers médicaux',
            self::EquipeGerer => 'Gérer l’équipe',
            self::EtablissementRegler => 'Régler l’établissement',
            self::StatsVoir => 'Voir les statistiques',
            self::FileEmettre => 'Donner des tickets',
            self::FileAppeler => 'Appeler les patients',
            self::FileGerer => 'Régler la file (services, guichets, écrans)',
            self::RdvVoir => 'Voir les rendez-vous',
            self::RdvGerer => 'Gérer les rendez-vous et les agendas',
            self::ConsultationFaire => 'Faire des consultations',
            self::OrdonnancePrescrire => 'Prescrire des ordonnances',
            self::CaisseEncaisser => 'Encaisser',
            self::CaisseRapports => 'Voir les rapports de caisse',
            self::PharmacieDelivrer => 'Délivrer des médicaments',
            self::StockGerer => 'Gérer le stock',
            self::LaboSaisir => 'Saisir les résultats',
            self::LaboValider => 'Valider les résultats',
            self::MaterniteSuivre => 'Suivre les grossesses',
            self::HospitalisationGerer => 'Gérer les lits et les admissions',
            self::SoinsAdministrer => 'Administrer les soins',
            self::BadgesGerer => 'Gérer les badges',
            self::AssurancesGerer => 'Gérer les assurances',
        };
    }

    /**
     * Droits par défaut d'un rôle, avant tout réglage de l'établissement.
     *
     * @return list<self>
     */
    public static function parDefaut(Role $role): array
    {
        $tous = self::cases();
        $soignant = [
            self::PatientsVoir, self::PatientsGerer, self::DossierLire, self::DossierEcrire,
            self::FileAppeler, self::RdvVoir,
        ];

        return match ($role) {
            Role::Admin, Role::AdminEtablissement => $tous,
            Role::Medecin => [
                ...$soignant, self::RdvGerer, self::ConsultationFaire, self::OrdonnancePrescrire,
                self::MaterniteSuivre, self::HospitalisationGerer, self::SoinsAdministrer, self::StatsVoir,
            ],
            Role::SageFemme => [
                ...$soignant, self::RdvGerer, self::ConsultationFaire, self::OrdonnancePrescrire,
                self::MaterniteSuivre, self::SoinsAdministrer,
            ],
            Role::Infirmier => [...$soignant, self::FileEmettre, self::SoinsAdministrer, self::HospitalisationGerer],
            Role::Pharmacien => [
                self::PatientsVoir, self::FileAppeler, self::PharmacieDelivrer, self::StockGerer,
                self::CaisseEncaisser,
            ],
            Role::Laborantin => [self::PatientsVoir, self::FileAppeler, self::RdvVoir, self::LaboSaisir, self::LaboValider],
            Role::Caissier => [
                self::PatientsVoir, self::FileEmettre, self::FileAppeler, self::CaisseEncaisser,
                self::AssurancesGerer,
            ],
            Role::Accueil => [
                self::PatientsVoir, self::PatientsGerer, self::FileEmettre, self::FileAppeler,
                self::RdvVoir, self::RdvGerer,
            ],
            Role::Gestionnaire => [
                self::PatientsVoir, self::StatsVoir, self::StockGerer, self::CaisseRapports,
                self::FileGerer, self::RdvVoir, self::BadgesGerer, self::AssurancesGerer,
            ],
        };
    }
}
