<?php

declare(strict_types=1);

namespace App\Sante;

/**
 * Les QR de Ngoni Santé : « ngoni-sante:<type>:<valeur> ».
 *
 * - rdv:<uuid>          rendez-vous (arrivée)
 * - carte:<jeton>       carte santé de la personne
 * - etab:<uuid>         affiche de l'accueil d'un établissement
 * - appairer:<code>     appairage d'un écran ou d'une borne
 *
 * Le QR d'un ticket est une URL (page de suivi lisible sans l'app).
 */
final class Qr
{
    public const RDV = 'rdv';

    public const CARTE = 'carte';

    public const ETABLISSEMENT = 'etab';

    public static function creer(string $type, string $valeur): string
    {
        return "ngoni-sante:{$type}:{$valeur}";
    }

    /**
     * @return array{0: string, 1: string}|null [type, valeur]
     */
    public static function lire(string $qr): ?array
    {
        if (preg_match('/^ngoni-sante:([a-z]+):([A-Za-z0-9-]{4,64})$/', trim($qr), $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
