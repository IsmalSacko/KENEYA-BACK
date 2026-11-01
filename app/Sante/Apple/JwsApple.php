<?php

declare(strict_types=1);

namespace App\Sante\Apple;

use OpenSSLCertificate;

/**
 * JWS signé par l'App Store (transaction StoreKit 2, notification V2), vérifié
 * sans bibliothèque, comme le fait celle d'Apple :
 *
 * - en-tête ES256 et chaîne x5c de trois certificats : feuille, intermédiaire, racine ;
 * - racine identique, octet pour octet, au certificat d'Apple gardé sur le serveur ;
 * - chaque certificat signé par le suivant, et valide à la date de signature ;
 * - marqueurs d'Apple sur la feuille et l'intermédiaire : sous la même racine,
 *   Apple délivre aussi des certificats aux développeurs, qui ne doivent pas
 *   pouvoir signer un achat ;
 * - signature ES256 du JWS avec la clé de la feuille.
 *
 * Rien du contenu n'est rendu avant que tout soit vérifié.
 */
class JwsApple
{
    /** Feuille qui signe les achats (« Mac App Store and iTunes Store Receipt Signing »). */
    public const OID_FEUILLE = '1.2.840.113635.100.6.11.1';

    /** Intermédiaire « Apple Worldwide Developer Relations ». */
    public const OID_INTERMEDIAIRE = '1.2.840.113635.100.6.2.1';

    /** @param  string|null  $racine  chemin du certificat racine (DER ou PEM) ; défaut : config apple.racine */
    public function __construct(private readonly ?string $racine = null) {}

    /**
     * Le contenu signé, une fois la signature et la chaîne vérifiées.
     *
     * @return array<string, mixed>
     *
     * @throws JwsInvalide
     */
    public function verifier(string $jws): array
    {
        $parties = explode('.', trim($jws));
        if (count($parties) !== 3) {
            throw new JwsInvalide('JWS mal formé.');
        }
        [$entete64, $contenu64, $signature64] = $parties;

        $entete = self::json($entete64);
        if (($entete['alg'] ?? null) !== 'ES256') {
            throw new JwsInvalide('Algorithme non accepté.');
        }
        $x5c = $entete['x5c'] ?? null;
        if (! is_array($x5c) || count($x5c) !== 3 || ! array_is_list($x5c)) {
            throw new JwsInvalide('Chaîne de certificats absente.');
        }
        [$feuille, $intermediaire, $racine] = array_map(self::certificat(...), $x5c);

        if (! hash_equals($this->racineDeConfiance(), $racine['der'])) {
            throw new JwsInvalide('Racine inconnue.');
        }
        $this->verifierChaine($feuille['cert'], $intermediaire['cert'], $racine['cert']);
        $this->verifierSignature($entete64.'.'.$contenu64, self::base64url($signature64), $feuille['cert']);

        $contenu = self::json($contenu64);

        // Les dates à la signature par Apple : un achat ancien, renvoyé par
        // l'application, reste lisible après le renouvellement d'un certificat.
        $signeLe = is_numeric($contenu['signedDate'] ?? null) ? intdiv((int) $contenu['signedDate'], 1000) : time();
        foreach ([$feuille, $intermediaire, $racine] as $c) {
            $infos = openssl_x509_parse($c['cert']);
            if ($infos === false || $signeLe < ($infos['validFrom_time_t'] ?? PHP_INT_MAX) || $signeLe > ($infos['validTo_time_t'] ?? 0)) {
                throw new JwsInvalide('Certificat hors de sa période de validité.');
            }
        }

        return $contenu;
    }

    private function verifierChaine(OpenSSLCertificate $feuille, OpenSSLCertificate $intermediaire, OpenSSLCertificate $racine): void
    {
        $cleRacine = openssl_pkey_get_public($racine);
        $cleIntermediaire = openssl_pkey_get_public($intermediaire);
        if ($cleRacine === false || $cleIntermediaire === false
            || openssl_x509_verify($intermediaire, $cleRacine) !== 1
            || openssl_x509_verify($feuille, $cleIntermediaire) !== 1) {
            throw new JwsInvalide('Chaîne de certificats non reconnue.');
        }

        $extensionsIntermediaire = openssl_x509_parse($intermediaire)['extensions'] ?? [];
        $extensionsFeuille = openssl_x509_parse($feuille)['extensions'] ?? [];
        if (! str_contains((string) ($extensionsIntermediaire['basicConstraints'] ?? ''), 'CA:TRUE')
            || ! array_key_exists(self::OID_INTERMEDIAIRE, $extensionsIntermediaire)
            || ! array_key_exists(self::OID_FEUILLE, $extensionsFeuille)) {
            throw new JwsInvalide('Certificats sans les marqueurs d’Apple.');
        }
    }

    /** ES256 : signature brute R‖S de 64 octets, que openssl attend en DER. */
    private function verifierSignature(string $signe, string $signature, OpenSSLCertificate $feuille): void
    {
        $cle = openssl_pkey_get_public($feuille);
        $details = $cle === false ? false : openssl_pkey_get_details($cle);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new JwsInvalide('Clé de signature non acceptée.');
        }
        if (strlen($signature) !== 64 || openssl_verify($signe, self::derDepuisBrut($signature), $cle, OPENSSL_ALGO_SHA256) !== 1) {
            throw new JwsInvalide('Signature invalide.');
        }
    }

    /** SEQUENCE { INTEGER r, INTEGER s } : entiers sans zéros de tête, signés positifs. */
    public static function derDepuisBrut(string $brut): string
    {
        $entier = function (string $octets): string {
            $octets = ltrim($octets, "\x00");
            if ($octets === '' || ord($octets[0]) > 0x7F) {
                $octets = "\x00".$octets;
            }

            return "\x02".chr(strlen($octets)).$octets;
        };
        $suite = $entier(substr($brut, 0, 32)).$entier(substr($brut, 32, 32));

        return "\x30".chr(strlen($suite)).$suite;
    }

    /** Le certificat racine configuré, en DER (le fichier d'Apple est en DER ; un PEM est accepté). */
    private function racineDeConfiance(): string
    {
        $chemin = $this->racine ?? config()->string('apple.racine');
        $contenu = is_file($chemin) ? (string) file_get_contents($chemin) : '';
        if (str_contains($contenu, '-----BEGIN CERTIFICATE-----')) {
            $contenu = (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $contenu), true);
        }
        if ($contenu === '') {
            throw new JwsInvalide('Certificat racine d’Apple introuvable sur le serveur.');
        }

        return $contenu;
    }

    /** @return array{der: string, cert: OpenSSLCertificate} */
    private static function certificat(mixed $base64): array
    {
        $der = is_string($base64) ? base64_decode($base64, true) : false;
        $cert = $der === false || $der === '' ? false
            : openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n");
        if ($cert === false) {
            throw new JwsInvalide('Certificat illisible.');
        }

        return ['der' => $der, 'cert' => $cert];
    }

    /** @return array<string, mixed> */
    private static function json(string $base64url): array
    {
        $json = json_decode(self::base64url($base64url), true);
        if (! is_array($json)) {
            throw new JwsInvalide('Contenu illisible.');
        }

        // Un objet JSON : ses champs ont des noms.
        return array_filter($json, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private static function base64url(string $texte): string
    {
        $octets = base64_decode(str_pad(strtr($texte, '-_', '+/'), (int) (ceil(strlen($texte) / 4) * 4), '='), true);
        if ($octets === false) {
            throw new JwsInvalide('Encodage illisible.');
        }

        return $octets;
    }
}
