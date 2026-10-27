<?php

declare(strict_types=1);

namespace App\Sante\File;

/**
 * Priorité d'un ticket. L'urgence passe toujours en premier ; les
 * prioritaires (rendez-vous, femme enceinte, personne âgée, handicap) avant
 * les ordinaires, sans les faire attendre indéfiniment (voir FileAttente).
 */
enum Priorite: string
{
    case Urgence = 'urgence';
    case RendezVous = 'rdv';
    case Prioritaire = 'prioritaire';
    case Ordinaire = 'ordinaire';

    /** Plus petit = appelé plus tôt. */
    public function rang(): int
    {
        return match ($this) {
            self::Urgence => 0,
            self::RendezVous => 1,
            self::Prioritaire => 2,
            self::Ordinaire => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Urgence => 'Urgence',
            self::RendezVous => 'Rendez-vous',
            self::Prioritaire => 'Prioritaire',
            self::Ordinaire => 'Ordinaire',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
