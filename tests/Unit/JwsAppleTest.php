<?php

declare(strict_types=1);

use App\Sante\Apple\JwsApple;
use App\Sante\Apple\JwsInvalide;
use Tests\Support\FabriqueApple;

/*
 * Vérification des JWS de l'App Store, sur une fausse autorité « Apple »
 * fabriquée avec openssl (FabriqueApple).
 */

function verificateurApple(): JwsApple
{
    return new JwsApple(FabriqueApple::commune()->racine);
}

function jwsRefuse(string $jws, string $message, ?JwsApple $verificateur = null): void
{
    expect(fn () => ($verificateur ?? verificateurApple())->verifier($jws))->toThrow(JwsInvalide::class, $message);
}

it('rend le contenu d’un JWS signé par la chaîne d’Apple', function () {
    $contenu = ['transactionId' => '2000000000000001', 'signedDate' => time() * 1000];

    // Des centaines de signatures : R et S courts ou à bit fort, la conversion en DER tient.
    for ($i = 0; $i < 200; $i++) {
        expect(verificateurApple()->verifier(FabriqueApple::commune()->jws([...$contenu, 'i' => $i])))->toBe([...$contenu, 'i' => $i]);
    }
});

it('refuse un contenu ou une signature retouchés', function () {
    $apple = FabriqueApple::commune();
    [$entete, , $signature] = explode('.', $apple->jws(['productId' => 'ngonisante.essentiel.mensuel', 'signedDate' => time() * 1000]));
    $autre = FabriqueApple::base64url((string) json_encode(['productId' => 'ngonisante.hopital.annuel', 'signedDate' => time() * 1000]));
    jwsRefuse($entete.'.'.$autre.'.'.$signature, 'Signature invalide.');

    [$entete, $contenu] = explode('.', $apple->jws(['signedDate' => time() * 1000]));
    jwsRefuse($entete.'.'.$contenu.'.'.FabriqueApple::base64url(random_bytes(64)), 'Signature invalide.');
    jwsRefuse($entete.'.'.$contenu.'.'.FabriqueApple::base64url(random_bytes(70)), 'Signature invalide.');
});

it('refuse une chaîne qui ne mène pas à la racine d’Apple', function () {
    $intrus = new FabriqueApple;

    // Sa propre chaîne : racine inconnue.
    jwsRefuse($intrus->jws(['signedDate' => time() * 1000]), 'Racine inconnue.');

    // La vraie racine annoncée en bout de chaîne, sans en descendre.
    $chaine = [$intrus->x5c[0], $intrus->x5c[1], FabriqueApple::commune()->x5c[2]];
    jwsRefuse($intrus->jws(['signedDate' => time() * 1000], $chaine), 'Chaîne de certificats non reconnue.');

    // La vraie chaîne annoncée, mais signé avec une autre clé.
    jwsRefuse($intrus->jws(['signedDate' => time() * 1000], FabriqueApple::commune()->x5c), 'Signature invalide.');
});

it('ne laisse pas un certificat sans les marqueurs d’Apple signer un achat, même sous sa racine', function () {
    // Ce que serait un certificat de développeur délivré sous la même racine.
    $developpeur = new FabriqueApple(marqueurs: false);

    jwsRefuse($developpeur->jws(['signedDate' => time() * 1000]), 'Certificats sans les marqueurs d’Apple.', new JwsApple($developpeur->racine));
});

it('refuse un JWS signé hors de la période des certificats', function () {
    jwsRefuse(FabriqueApple::commune()->jws(['signedDate' => (time() + 400 * 86400) * 1000]), 'Certificat hors de sa période de validité.');
    jwsRefuse(FabriqueApple::commune()->jws(['signedDate' => (time() - 86400) * 1000]), 'Certificat hors de sa période de validité.');
});

it('refuse un JWS mal formé ou d’un autre algorithme', function () {
    $apple = FabriqueApple::commune();
    jwsRefuse('pas un jws', 'JWS mal formé.');
    jwsRefuse('a.b.c', 'Encodage illisible.');
    jwsRefuse(FabriqueApple::base64url('pas du json').'.b.c', 'Contenu illisible.');
    jwsRefuse($apple->jws(['signedDate' => time() * 1000], alg: 'RS256'), 'Algorithme non accepté.');
    jwsRefuse($apple->jws(['signedDate' => time() * 1000], [$apple->x5c[0]]), 'Chaîne de certificats absente.');
    jwsRefuse($apple->jws(['signedDate' => time() * 1000], ['pas du base64 !', 'b', 'c']), 'Certificat illisible.');
});

it('n’accepte rien sans le certificat racine sur le serveur', function () {
    jwsRefuse(FabriqueApple::commune()->jws(['signedDate' => time() * 1000]), 'Certificat racine d’Apple introuvable sur le serveur.', new JwsApple('/inexistant/AppleRootCA-G3.cer'));
});

it('accepte une racine configurée en PEM', function () {
    $pem = (string) tempnam(sys_get_temp_dir(), 'racine-pem-');
    file_put_contents($pem, "-----BEGIN CERTIFICATE-----\n".chunk_split(FabriqueApple::commune()->x5c[2], 64, "\n")."-----END CERTIFICATE-----\n");

    $contenu = ['signedDate' => time() * 1000];
    try {
        expect((new JwsApple($pem))->verifier(FabriqueApple::commune()->jws($contenu)))->toBe($contenu);
    } finally {
        unlink($pem);
    }
});

it('reconnaît le vrai certificat racine d’Apple livré avec le serveur', function () {
    $der = (string) file_get_contents(__DIR__.'/../../resources/certificats/AppleRootCA-G3.cer');

    expect(hash('sha256', $der))->toBe('63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179');
});
