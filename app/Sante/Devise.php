<?php

declare(strict_types=1);

namespace App\Sante;

/**
 * Montants : la caisse compte en entiers dans l'unité de la devise (le franc
 * CFA n'a pas de centimes ; le cedi en a, on compte des pesewas). Les prix
 * des médicaments (app 3.x) sont en décimal : on convertit ici.
 */
final class Devise
{
    private const SANS_DECIMALES = ['XOF', 'XAF', 'GNF'];

    public static function decimales(string $devise): int
    {
        return in_array($devise, self::SANS_DECIMALES, true) ? 0 : 2;
    }

    public static function versMineur(float|int|string|null $montant, string $devise): int
    {
        return (int) round((float) $montant * (10 ** self::decimales($devise)));
    }
}
