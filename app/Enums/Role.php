<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rôles utilisateur de KENEYA.
 *
 * Deux niveaux d'administration :
 *  - Admin (instance)        : gère TOUTE la plateforme (tous les établissements).
 *  - Admin d'établissement    : mêmes pouvoirs mais restreints à SON établissement.
 * Les autres rôles sont opérationnels (aucune action d'administration).
 */
enum Role: string
{
    case Admin = 'admin';                      // administrateur de l'instance (plateforme)
    case AdminEtablissement = 'admin_etablissement'; // administrateur d'un seul établissement
    case Medecin = 'medecin';
    case Infirmier = 'infirmier';
    case SageFemme = 'sage_femme';
    case Pharmacien = 'pharmacien';
    case Laborantin = 'laborantin';
    case Caissier = 'caissier';
    case Gestionnaire = 'gestionnaire';
    case Accueil = 'accueil';

    /**
     * Toutes les valeurs de rôle (pour les règles de validation / migrations).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }

    /**
     * Rôles disposant des pouvoirs d'administration d'un établissement
     * (ajout/modification/suppression d'utilisateurs, réglages de l'établissement).
     *
     * @return list<string>
     */
    public static function etablissementAdmins(): array
    {
        return [self::Admin->value, self::AdminEtablissement->value];
    }

    /**
     * Rôles qu'un admin d'établissement a le droit d'attribuer :
     * tout sauf l'admin d'instance (pas d'escalade de privilège).
     *
     * @return list<string>
     */
    public static function assignableByEtablissementAdmin(): array
    {
        return array_values(array_filter(
            self::values(),
            static fn (string $r): bool => $r !== self::Admin->value,
        ));
    }

    /**
     * Libellé lisible (fr).
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::AdminEtablissement => 'Admin d’établissement',
            self::Medecin => 'Médecin',
            self::Infirmier => 'Infirmier',
            self::SageFemme => 'Sage-femme',
            self::Pharmacien => 'Pharmacien',
            self::Laborantin => 'Laborantin',
            self::Caissier => 'Caissier',
            self::Gestionnaire => 'Gestionnaire',
            self::Accueil => 'Accueil',
        };
    }
}
