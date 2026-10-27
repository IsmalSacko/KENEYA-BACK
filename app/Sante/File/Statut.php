<?php

declare(strict_types=1);

namespace App\Sante\File;

/**
 * émis → appelé → en service → terminé ; appelé → absent (rappelable) ;
 * en service → transféré (un nouveau ticket prend la suite) ; annulé.
 */
enum Statut: string
{
    case Emis = 'emis';
    case Appele = 'appele';
    case EnService = 'en_service';
    case Termine = 'termine';
    case Absent = 'absent';
    case Transfere = 'transfere';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Emis => 'En attente',
            self::Appele => 'Appelé',
            self::EnService => 'En cours',
            self::Termine => 'Terminé',
            self::Absent => 'Absent',
            self::Transfere => 'Transféré',
            self::Annule => 'Annulé',
        };
    }

    /** Le ticket est encore « vivant » dans la file. */
    public function enCours(): bool
    {
        return in_array($this, [self::Emis, self::Appele, self::EnService, self::Absent], true);
    }
}
