<?php

declare(strict_types=1);

namespace App\Sante\Pharmacie;

use App\Models\Ordonnance;

/**
 * Le QR d'une ordonnance porte son contenu signé (Ed25519). Une pharmacie
 * le vérifie avec la clé publique, même sans internet : une ordonnance
 * modifiée ou inventée ne passe pas.
 *
 * QR : « ngoni-sante:ord:<contenu>.<signature> », en base64url.
 * La paire de clés vient de SANTE_ORDONNANCES_GRAINE, sinon de la clé de
 * l'application : rien à installer, et stable d'un déploiement à l'autre.
 */
final class SignatureOrdonnances
{
    private const PREFIXE = 'ngoni-sante:ord:';

    public function clePublique(): string
    {
        return self::b64(sodium_crypto_sign_publickey($this->paire()));
    }

    /**
     * Ce que contient le QR, assez pour délivrer hors ligne.
     *
     * @return array<string, mixed>
     */
    public function contenu(Ordonnance $ordonnance): array
    {
        $ordonnance->loadMissing('lignes', 'etablissement', 'prescripteur');

        return [
            'v' => 1,
            'u' => $ordonnance->uuid,
            'e' => $ordonnance->etablissement->nom,
            'm' => $ordonnance->prescripteur?->getAttribute('name'),
            'p' => $ordonnance->patient_nom,
            'd' => $ordonnance->created_at?->toDateString(),
            'l' => $ordonnance->lignes->map(fn ($l): array => [$l->produit, $l->quantite, $l->posologie])->values()->all(),
        ];
    }

    /**
     * « <contenu>.<signature> », gardé tel quel avec l'ordonnance : le QR
     * réimprimé plus tard reste identique, même si un nom change entre-temps.
     */
    public function signer(Ordonnance $ordonnance): string
    {
        $donnees = self::b64((string) json_encode($this->contenu($ordonnance), JSON_UNESCAPED_UNICODE));

        return $donnees.'.'.self::b64(sodium_crypto_sign_detached($donnees, sodium_crypto_sign_secretkey($this->paire())));
    }

    public function qr(Ordonnance $ordonnance): string
    {
        return self::PREFIXE.$ordonnance->signature;
    }

    /**
     * Le contenu d'un QR d'ordonnance, s'il est authentique ; sinon null.
     *
     * @return array<string, mixed>|null
     */
    public function lire(string $qr): ?array
    {
        $qr = trim($qr);
        if (! str_starts_with($qr, self::PREFIXE)) {
            return null;
        }
        $morceaux = explode('.', substr($qr, strlen(self::PREFIXE)));
        if (count($morceaux) !== 2) {
            return null;
        }
        [$donnees, $signature] = $morceaux;
        $sig = self::deb64($signature);
        if ($sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (! sodium_crypto_sign_verify_detached($sig, $donnees, sodium_crypto_sign_publickey($this->paire()))) {
            return null;
        }
        $json = self::deb64($donnees);
        $contenu = $json === null ? null : json_decode($json, true);
        if (! is_array($contenu)) {
            return null;
        }
        $propre = [];
        foreach ($contenu as $cle => $valeur) {
            if (is_string($cle)) {
                $propre[$cle] = $valeur;
            }
        }

        return $propre;
    }

    /**
     * @return non-empty-string
     */
    private function paire(): string
    {
        $graine = config('sante.ordonnances.graine');
        $cleApp = config('app.key');
        $graine = is_string($graine) && $graine !== '' ? $graine : (is_string($cleApp) ? $cleApp : '');

        return sodium_crypto_sign_seed_keypair(hash('sha256', 'ordonnances|'.$graine, true));
    }

    private static function b64(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    private static function deb64(string $texte): ?string
    {
        $octets = base64_decode(strtr($texte, '-_', '+/'), true);

        return $octets === false ? null : $octets;
    }
}
