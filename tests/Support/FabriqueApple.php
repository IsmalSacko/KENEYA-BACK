<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Sante\Apple\JwsApple;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Une fausse autorité « Apple » pour les tests, fabriquée avec openssl :
 * racine, intermédiaire et feuille ES256 (avec les marqueurs d'Apple), et des
 * JWS signés comme ceux de l'App Store. Jamais de vrai certificat d'Apple.
 */
class FabriqueApple
{
    /** Fichier DER de la racine, à mettre dans config('apple.racine'). */
    public readonly string $racine;

    /** @var list<string> la chaîne x5c : feuille, intermédiaire, racine (base64 DER) */
    public readonly array $x5c;

    private OpenSSLAsymmetricKey $cleFeuille;

    private static ?self $commune = null;

    /** La même fausse autorité pour toute la suite : la fabriquer coûte trois certificats. */
    public static function commune(): self
    {
        return self::$commune ??= new self;
    }

    public function __construct(bool $marqueurs = true)
    {
        $config = self::configuration();
        $cleRacine = self::cle($config);
        $racine = self::signer(['CN' => 'Fausse racine Apple'], $cleRacine, null, $cleRacine, 'racine', $config);
        $cleIntermediaire = self::cle($config);
        $intermediaire = self::signer(['CN' => 'Faux intermédiaire Apple'], $cleIntermediaire, $racine, $cleRacine, $marqueurs ? 'intermediaire' : 'intermediaire_sans_marqueur', $config);
        $this->cleFeuille = self::cle($config);
        $feuille = self::signer(['CN' => 'Fausse feuille App Store'], $this->cleFeuille, $intermediaire, $cleIntermediaire, $marqueurs ? 'feuille' : 'feuille_sans_marqueur', $config);
        unlink($config);

        $this->x5c = array_map(self::base64Der(...), [$feuille, $intermediaire, $racine]);
        $this->racine = (string) tempnam(sys_get_temp_dir(), 'racine-apple-');
        file_put_contents($this->racine, base64_decode($this->x5c[2]));
    }

    public function __destruct()
    {
        if (is_file($this->racine)) {
            unlink($this->racine);
        }
    }

    /**
     * Un JWS signé par la feuille, comme l'App Store.
     *
     * @param  array<string, mixed>  $contenu
     * @param  list<string>|null  $x5c  une autre chaîne annoncée dans l'en-tête
     */
    public function jws(array $contenu, ?array $x5c = null, string $alg = 'ES256'): string
    {
        $signe = self::base64url((string) json_encode(['alg' => $alg, 'x5c' => $x5c ?? $this->x5c]))
            .'.'.self::base64url((string) json_encode($contenu));
        openssl_sign($signe, $der, $this->cleFeuille, OPENSSL_ALGO_SHA256);

        return $signe.'.'.self::base64url(self::brutDepuisDer($der));
    }

    public static function base64url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    /** DER (SEQUENCE de deux INTEGER) → R‖S de 64 octets, la forme d'un JWS. */
    private static function brutDepuisDer(string $der): string
    {
        $position = 2;
        $longueurR = ord($der[$position + 1]);
        $r = substr($der, $position + 2, $longueurR);
        $position += 2 + $longueurR;
        $s = substr($der, $position + 2, ord($der[$position + 1]));

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private static function cle(string $config): OpenSSLAsymmetricKey
    {
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
        if ($cle === false) {
            throw new RuntimeException('Clé EC non créée : '.openssl_error_string());
        }

        return $cle;
    }

    /** @param  array<string, string>  $nom */
    private static function signer(array $nom, OpenSSLAsymmetricKey $cle, ?OpenSSLCertificate $emetteur, OpenSSLAsymmetricKey $cleEmetteur, string $extensions, string $config): OpenSSLCertificate
    {
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];
        $demande = openssl_csr_new($nom, $cle, $options);
        $cert = $demande === false || $demande === true ? false : openssl_csr_sign($demande, $emetteur, $cleEmetteur, 365, $options, random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('Certificat non signé : '.openssl_error_string());
        }

        return $cert;
    }

    private static function base64Der(OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $pem);

        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /** Les extensions des trois certificats, marqueurs d'Apple compris (ou sans, pour les refuser). */
    private static function configuration(): string
    {
        $feuille = JwsApple::OID_FEUILLE;
        $intermediaire = JwsApple::OID_INTERMEDIAIRE;
        $chemin = (string) tempnam(sys_get_temp_dir(), 'openssl-apple-');
        file_put_contents($chemin, <<<CNF
            [ req ]
            distinguished_name = nom
            [ nom ]
            [ racine ]
            basicConstraints = critical, CA:TRUE
            keyUsage = critical, keyCertSign, cRLSign
            [ intermediaire ]
            basicConstraints = critical, CA:TRUE, pathlen:0
            keyUsage = critical, keyCertSign, cRLSign
            {$intermediaire} = ASN1:NULL
            [ intermediaire_sans_marqueur ]
            basicConstraints = critical, CA:TRUE, pathlen:0
            keyUsage = critical, keyCertSign, cRLSign
            [ feuille ]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            {$feuille} = ASN1:NULL
            [ feuille_sans_marqueur ]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            CNF);

        return $chemin;
    }
}
