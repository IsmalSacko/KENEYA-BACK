<?php

declare(strict_types=1);

namespace App\Sante;

use App\Models\Pays;

/**
 * Numéros au format international (+22376123456) : une personne a un seul
 * numéro, quel que soit le pays où elle se soigne.
 */
final class Telephone
{
    /**
     * Normalise un numéro saisi localement (« 76 12 34 56 ») ou en
     * international (« +223… », « 00223… ») pour le pays donné.
     */
    public static function normaliser(string $saisi, Pays $pays): string
    {
        $international = str_starts_with(trim($saisi), '+') || str_starts_with(trim($saisi), '00');
        $chiffres = (string) preg_replace('/\D+/', '', $saisi);

        if ($international) {
            return '+'.(str_starts_with(trim($saisi), '00') ? substr($chiffres, 2) : $chiffres);
        }

        // « 22376123456 » : déjà international, seul le + manque. Un numéro
        // national ne commence jamais par l'indicatif de son pays.
        if (str_starts_with($chiffres, $pays->indicatif) && strlen($chiffres) >= strlen($pays->indicatif) + 8) {
            return '+'.$chiffres;
        }

        // Certains pays (Ghana, Nigeria…) composent un 0 en national qu'on
        // retire en international ; d'autres (Côte d'Ivoire) le gardent.
        if ($pays->supprimer_zero_initial) {
            $chiffres = ltrim($chiffres, '0');
        }

        return '+'.$pays->indicatif.$chiffres;
    }
}
