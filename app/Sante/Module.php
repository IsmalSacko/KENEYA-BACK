<?php

declare(strict_types=1);

namespace App\Sante;

/**
 * Modules métier qu'un établissement peut activer. Le profil en coche un jeu
 * par défaut ; l'établissement peut ensuite en ajouter ou en retirer.
 */
enum Module: string
{
    case File = 'file';
    case RendezVous = 'rdv';
    case Consultation = 'consultation';
    case Caisse = 'caisse';
    case Pharmacie = 'pharmacie';
    case Labo = 'labo';
    case Maternite = 'maternite';
    case Hospitalisation = 'hospitalisation';
    case Badges = 'badges';
    case Assurances = 'assurances';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::File => "File d'attente",
            self::RendezVous => 'Rendez-vous',
            self::Consultation => 'Consultation',
            self::Caisse => 'Caisse',
            self::Pharmacie => 'Pharmacie',
            self::Labo => 'Laboratoire',
            self::Maternite => 'Maternité',
            self::Hospitalisation => 'Hospitalisation',
            self::Badges => 'Badges',
            self::Assurances => 'Assurances',
        };
    }
}
