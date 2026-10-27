<?php

declare(strict_types=1);

namespace App\Sante\Pharmacie;

/** Les rayons d'une officine : tout ce qu'on y vend n'est pas un médicament. */
enum Rayon: string
{
    case Medicaments = 'medicaments';
    case Contraception = 'contraception';
    case Bebe = 'bebe';
    case HygieneBeaute = 'hygiene_beaute';
    case MaterielMedical = 'materiel_medical';
    case Complements = 'complements';
    case Autres = 'autres';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Medicaments => 'Médicaments',
            self::Contraception => 'Contraception',
            self::Bebe => 'Bébé',
            self::HygieneBeaute => 'Hygiène et beauté',
            self::MaterielMedical => 'Matériel médical',
            self::Complements => 'Compléments',
            self::Autres => 'Autres',
        };
    }
}
