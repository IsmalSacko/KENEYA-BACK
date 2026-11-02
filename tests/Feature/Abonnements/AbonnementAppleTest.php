<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\JournalAbonnement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FabriqueApple;

/*
 * Abonnement acheté dans l'application iPhone (achat intégré Apple) :
 * transaction signée vérifiée puis accordée à l'établissement jusqu'à la fin
 * vendue par Apple, renouvellements, fins et remboursements suivis par les
 * notifications V2. La chaîne « Apple » est fabriquée pour les tests.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'apple.racine' => FabriqueApple::commune()->racine,
        'apple.environnements' => ['Sandbox', 'Production'],
        'sante.console.cle' => 'cle-de-test',
        'sante.alertes.emails' => '',
        'mail.default' => 'array',
    ]);
    // Le lendemain matin : dans la période des certificats de la fausse autorité.
    Carbon::setTestNow(Carbon::now()->addDay()->setTime(10, 0));

    $this->etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $this->admin = User::factory()->create(['etablissement_id' => $this->etab->id, 'role' => 'admin_etablissement']);
    $this->actingAs($this->admin, 'api');
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> une transaction d'abonnement telle que StoreKit 2 la signe */
function transactionApple(array $plus = []): array
{
    return [
        'transactionId' => '2000000000000001',
        'originalTransactionId' => '2000000000000001',
        'bundleId' => 'com.ismaeldev.keneya',
        'productId' => 'ngonisante.pro.mensuel',
        'subscriptionGroupIdentifier' => '21000001',
        'purchaseDate' => now()->getTimestampMs(),
        'originalPurchaseDate' => now()->getTimestampMs(),
        'expiresDate' => now()->addMonth()->getTimestampMs(),
        'quantity' => 1,
        'type' => 'Auto-Renewable Subscription',
        'appAccountToken' => test()->etab->uuid,
        'inAppOwnershipType' => 'PURCHASED',
        'signedDate' => now()->getTimestampMs(),
        'environment' => 'Production',
        'transactionReason' => 'PURCHASE',
        'storefront' => 'MLI',
        'price' => 16990,
        'currency' => 'USD',
        ...$plus,
    ];
}

function acheterApple(array $plus = []): TestResponse
{
    return test()->postJson('/api/abonnement/apple', ['signed_transaction' => FabriqueApple::commune()->jws(transactionApple($plus))]);
}

/** Une notification V2 de l'App Store portant cette transaction. */
function notifierApple(string $type, array $transaction, ?string $sousType = null, ?FabriqueApple $signataire = null): TestResponse
{
    $signataire ??= FabriqueApple::commune();

    return test()->postJson('/api/apple/notifications', ['signedPayload' => $signataire->jws([
        'notificationType' => $type,
        'subtype' => $sousType,
        'notificationUUID' => '9ad56bd2-0bc6-42e0-af24-fd996d87a1e6',
        'data' => [
            'appAppleId' => 6829558069,
            'bundleId' => 'com.ismaeldev.keneya',
            'bundleVersion' => '40010',
            'environment' => $transaction['environment'] ?? 'Production',
            'signedTransactionInfo' => $signataire->jws($transaction),
            'status' => 1,
        ],
        'version' => '2.0',
        'signedDate' => now()->getTimestampMs(),
    ])]);
}

function abonnementApple(): Abonnement
{
    return Abonnement::where('etablissement_id', test()->etab->id)->firstOrFail();
}

function enteteConsoleApple(): array
{
    return ['Authorization' => 'Bearer cle-de-test', 'X-Console-Operateur' => 'Ismaila'];
}

it('accorde la formule achetée jusqu’à la fin vendue par Apple : demande payée, reçu, recette', function () {
    config(['sante.alertes.emails' => 'equipe@exemple.test']);
    $expire = now()->addMonth()->setTime(9, 30);

    // L'uuid de l'établissement, quelle que soit sa casse.
    acheterApple(['expiresDate' => $expire->getTimestampMs(), 'appAccountToken' => strtoupper($this->etab->uuid)])
        ->assertOk()
        ->assertJsonPath('abonnement.formule', 'pro')
        ->assertJsonPath('abonnement.essai', false)
        ->assertJsonPath('abonnement.en_cours', true)
        ->assertJsonPath('abonnement.fin', $expire->toDateString())
        ->assertJsonPath('abonnement.demande_en_attente', null);

    expect(abonnementApple()->apple_transaction_originale)->toBe('2000000000000001');

    // L'équipe est prévenue (avant toute autre requête : l'envoi suit la réponse).
    $mail = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect($mail->getSubject())->toBe('Ngoni Santé — Abonnement payé sur l’App Store : '.$this->etab->nom)
        ->and($mail->getTextBody())->toContain('Pro · 1 mois · 16,99 USD · jusqu’au '.$expire->format('d/m/Y'));

    // Une demande payée, approuvée d'elle-même, au prix de la formule ; ce qu'Apple a facturé est noté.
    $demande = DemandeAbonnement::sole();
    expect($demande->statut)->toBe('approuvee')
        ->and($demande->moyen)->toBe('apple_app_store')
        ->and($demande->reference)->toBe('2000000000000001')
        ->and($demande->apple_transaction_id)->toBe('2000000000000001')
        ->and($demande->montant)->toBe(15000)
        ->and($demande->devise)->toBe('XOF')
        ->and($demande->mois)->toBe(1)
        ->and($demande->periode_debut->toDateString())->toBe(today()->toDateString())
        ->and($demande->periode_fin->toDateString())->toBe($expire->toDateString())
        ->and($demande->recu_numero)->toMatch('/^A\d{2}-\d{4}$/')
        ->and($demande->note_validation)->toContain('16,99 USD')
        ->and($demande->decide_par_nom)->toBe('App Store')
        ->and($demande->demandeur_id)->toBe($this->admin->fresh()->personne_id);

    // La console la voit payée en ligne, et dans les recettes.
    $ligne = $this->getJson('/api/console/v1/demandes?statut=approuvee', enteteConsoleApple())->assertOk()->json('data.0');
    expect($ligne['automatique'])->toBeTrue()->and($ligne['moyen'])->toBe('apple_app_store')->and($ligne['par'])->toBe('App Store');
    $recettes = $this->getJson('/api/console/v1/recettes', enteteConsoleApple())->assertOk();
    expect($recettes->json('data.0.automatique'))->toBeTrue()->and($recettes->json('totaux.0.total'))->toBe(15000);
});

it('ne crée rien de plus quand la même transaction revient', function () {
    acheterApple()->assertOk();
    $fin = abonnementApple()->fin->toDateString();

    acheterApple()->assertOk()->assertJsonPath('abonnement.fin', $fin);
    // La notification d'Apple pour le même achat, après l'application.
    notifierApple('SUBSCRIBED', transactionApple(), 'INITIAL_BUY')->assertOk();

    expect(DemandeAbonnement::count())->toBe(1)->and(abonnementApple()->fin->toDateString())->toBe($fin);
});

it('refuse un achat d’une autre application, d’un produit inconnu ou d’un autre établissement', function () {
    $autre = Etablissement::factory()->create();

    acheterApple(['bundleId' => 'com.autre.application'])->assertStatus(422)
        ->assertJsonPath('message', 'Cet achat a été fait dans une autre application.');
    acheterApple(['productId' => 'ngonisante.or.mensuel'])->assertStatus(422)
        ->assertJsonPath('message', 'Ce produit de l’App Store n’est pas une formule Ngoni Santé.');
    acheterApple(['appAccountToken' => $autre->uuid])->assertStatus(422)
        ->assertJsonPath('message', 'Cet achat de l’App Store a été fait pour un autre établissement : il ne peut pas servir à celui-ci.');
    acheterApple(['appAccountToken' => null])->assertStatus(422);
    $this->postJson('/api/abonnement/apple', ['signed_transaction' => 'pas-un-jws'])->assertStatus(422)
        ->assertJsonPath('message', 'Cette transaction de l’App Store est illisible ou n’est pas signée par Apple.');
    $this->postJson('/api/abonnement/apple', [])->assertStatus(422)->assertJsonValidationErrors('signed_transaction');

    // Seul un administrateur achète pour l'établissement.
    $infirmier = User::factory()->create(['etablissement_id' => $this->etab->id, 'role' => 'infirmier']);
    $this->actingAs($infirmier, 'api');
    acheterApple()->assertForbidden();

    expect(DemandeAbonnement::count())->toBe(0)->and(abonnementApple()->formule)->toBe('essai');
});

it('n’ouvre rien pour une période déjà finie, remboursée, ou un abonnement sans échéance', function () {
    acheterApple(['expiresDate' => now()->subMinute()->getTimestampMs()])->assertStatus(422)
        ->assertJsonPath('message', 'Cet abonnement de l’App Store est déjà terminé : renouvelez-le depuis l’application.');
    acheterApple(['revocationDate' => now()->getTimestampMs(), 'revocationReason' => 0])->assertStatus(422)
        ->assertJsonPath('message', 'Cet achat a été remboursé par Apple : il n’ouvre plus d’abonnement.');
    expect(DemandeAbonnement::count())->toBe(0)->and(abonnementApple()->formule)->toBe('essai');

    // Accordé sans échéance par la plateforme : l'achat n'y change rien.
    abonnementApple()->update(['formule' => 'hopital', 'fin' => null, 'manuel' => true]);
    acheterApple()->assertStatus(422)
        ->assertJsonPath('message', 'L’abonnement de cet établissement est sans échéance : cet achat n’y change rien. Demandez son remboursement à Apple, ou écrivez au support.');
    expect(DemandeAbonnement::count())->toBe(0)->and(abonnementApple()->fin)->toBeNull();
});

it('refuse une signature falsifiée ou une chaîne inconnue', function () {
    $intrus = new FabriqueApple;
    [$entete, , $signature] = explode('.', FabriqueApple::commune()->jws(transactionApple()));
    $retouche = $entete.'.'.FabriqueApple::base64url((string) json_encode(transactionApple(['productId' => 'ngonisante.hopital.annuel']))).'.'.$signature;

    $this->postJson('/api/abonnement/apple', ['signed_transaction' => $retouche])->assertStatus(422);
    $this->postJson('/api/abonnement/apple', ['signed_transaction' => $intrus->jws(transactionApple())])->assertStatus(422);

    notifierApple('SUBSCRIBED', transactionApple(), signataire: $intrus)->assertStatus(400);
    $this->postJson('/api/apple/notifications', ['signedPayload' => $retouche])->assertStatus(400);
    $this->postJson('/api/apple/notifications', [])->assertStatus(400);
    // Notification signée par Apple, mais transaction glissée d'ailleurs.
    $this->postJson('/api/apple/notifications', ['signedPayload' => FabriqueApple::commune()->jws([
        'notificationType' => 'SUBSCRIBED',
        'data' => ['bundleId' => 'com.ismaeldev.keneya', 'appAppleId' => 6829558069, 'environment' => 'Production', 'signedTransactionInfo' => $intrus->jws(transactionApple())],
        'signedDate' => now()->getTimestampMs(),
    ])])->assertStatus(400);

    expect(DemandeAbonnement::count())->toBe(0)->and(abonnementApple()->formule)->toBe('essai');
});

it('accorde les achats de test (Sandbox) sans recette, jusqu’au soir', function () {
    config(['sante.alertes.emails' => 'equipe@exemple.test']);
    // Le soir, un « an » de Sandbox (une heure) : fini après minuit, il court jusqu'au lendemain.
    Carbon::setTestNow(now()->setTime(23, 30));

    acheterApple(['environment' => 'Sandbox', 'productId' => 'ngonisante.essentiel.annuel', 'expiresDate' => now()->addHour()->getTimestampMs()])
        ->assertOk()
        ->assertJsonPath('abonnement.formule', 'essentiel')
        ->assertJsonPath('abonnement.en_cours', true)
        ->assertJsonPath('abonnement.fin', today()->addDay()->toDateString());

    $demande = DemandeAbonnement::sole();
    expect($demande->montant)->toBe(0)->and($demande->mois)->toBe(12)->and($demande->note_validation)->toContain('Sandbox');
    expect(app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getTextBody())->toContain('achat de test (Sandbox)');

    // Sandbox refusé par la configuration : l'achat ne passe plus.
    config(['apple.environnements' => ['Production']]);
    acheterApple(['environment' => 'Sandbox', 'transactionId' => '2000000000000009', 'originalTransactionId' => '2000000000000009'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cet achat vient d’un environnement de l’App Store non accepté.');
});

it('prolonge à chaque renouvellement, sans qu’un message en retard raccourcisse rien', function () {
    acheterApple()->assertOk();

    // Renouvellement : Apple ne redonne pas forcément l'établissement, l'abonnement Apple suffit.
    $renouvellement = fn (string $id, int $mois) => transactionApple([
        'transactionId' => $id, 'transactionReason' => 'RENEWAL', 'appAccountToken' => null,
        'purchaseDate' => now()->addMonths($mois - 1)->getTimestampMs(), 'expiresDate' => now()->addMonths($mois)->getTimestampMs(),
    ]);
    notifierApple('DID_RENEW', $renouvellement('2000000000000002', 2))->assertOk();
    expect(abonnementApple()->fin->toDateString())->toBe(now()->addMonths(2)->toDateString())
        ->and(DemandeAbonnement::where('statut', 'approuvee')->count())->toBe(2);

    // Le quatrième mois arrive avant le troisième, rejoué en retard.
    notifierApple('DID_RENEW', $renouvellement('2000000000000004', 4), 'BILLING_RECOVERY')->assertOk();
    notifierApple('DID_RENEW', $renouvellement('2000000000000003', 3))->assertOk();

    expect(abonnementApple()->fin->toDateString())->toBe(now()->addMonths(4)->toDateString())
        ->and(DemandeAbonnement::count())->toBe(4)
        ->and(abonnementApple()->apple_transaction_originale)->toBe('2000000000000001');
});

it('accorde à l’établissement lié un achat annoncé par Apple seul', function () {
    notifierApple('SUBSCRIBED', transactionApple(['productId' => 'ngonisante.essentiel.mensuel']), 'INITIAL_BUY')->assertOk();

    expect(abonnementApple()->formule)->toBe('essentiel')->and(abonnementApple()->enCours())->toBeTrue();

    // Sans établissement lié ni abonnement Apple connu : accusée, rien d'accordé.
    notifierApple('SUBSCRIBED', transactionApple(['transactionId' => '7', 'originalTransactionId' => '7', 'appAccountToken' => null]))->assertOk();
    expect(DemandeAbonnement::count())->toBe(1);
});

it('arrête seulement l’abonnement que porte l’abonnement Apple qui finit', function (string $type) {
    acheterApple()->assertOk();

    // Un autre abonnement Apple (même établissement lié) : rien ne s'arrête.
    notifierApple($type, transactionApple(['transactionId' => '3000000000000001', 'originalTransactionId' => '3000000000000001']))->assertOk();
    expect(abonnementApple()->enCours())->toBeTrue();

    notifierApple($type, transactionApple())->assertOk();

    $abonnement = abonnementApple();
    expect($abonnement->enCours())->toBeFalse()
        ->and($abonnement->fin->toDateString())->toBe(today()->subDay()->toDateString());
    $journal = JournalAbonnement::where('etablissement_id', $this->etab->id)->where('type', 'revocation')->sole();
    expect($journal->par_nom)->toBe('App Store');
    $this->getJson('/api/abonnement')->assertJsonPath('abonnement.en_cours', false);
})->with(['fin' => 'EXPIRED', 'grâce écoulée' => 'GRACE_PERIOD_EXPIRED', 'remboursement' => 'REFUND', 'partage retiré' => 'REVOKE']);

it('ne laisse pas Apple couper un abonnement payé ensuite autrement', function () {
    acheterApple(['productId' => 'ngonisante.essentiel.mensuel'])->assertOk();

    // Payé ensuite par Orange Money, validé par la console : il n'est plus porté par Apple.
    $uuid = $this->postJson('/api/abonnement/demandes', ['formule' => 'pro', 'mois' => 12, 'moyen' => 'orange_money'])->assertCreated()->json('demande.uuid');
    $this->postJson("/api/console/v1/demandes/{$uuid}/approuver", [], enteteConsoleApple())->assertOk();
    $fin = abonnementApple()->fin->toDateString();
    expect(abonnementApple()->apple_transaction_originale)->toBeNull();

    notifierApple('EXPIRED', transactionApple(['productId' => 'ngonisante.essentiel.mensuel']), 'VOLUNTARY')->assertOk();
    notifierApple('REFUND', transactionApple(['productId' => 'ngonisante.essentiel.mensuel']))->assertOk();

    expect(abonnementApple()->enCours())->toBeTrue()
        ->and(abonnementApple()->formule)->toBe('pro')
        ->and(abonnementApple()->fin->toDateString())->toBe($fin);
});

it('ne raccourcit pas les mois de la même formule déjà payés autrement', function () {
    $fin = now()->addMonths(3)->toDateString();
    abonnementApple()->update(['formule' => 'pro', 'fin' => $fin]);

    acheterApple()->assertOk()->assertJsonPath('abonnement.fin', $fin);

    // La fin de cet abonnement Apple, avant celle déjà payée : rien ne s'arrête.
    notifierApple('EXPIRED', transactionApple(), 'VOLUNTARY')->assertOk();
    expect(abonnementApple()->enCours())->toBeTrue()->and(abonnementApple()->fin->toDateString())->toBe($fin);
});

it('accuse les autres notifications sans rien changer', function () {
    notifierApple('DID_CHANGE_RENEWAL_STATUS', transactionApple(), 'AUTO_RENEW_DISABLED')->assertOk();
    $this->postJson('/api/apple/notifications', ['signedPayload' => FabriqueApple::commune()->jws([
        'notificationType' => 'TEST',
        'data' => ['bundleId' => 'com.ismaeldev.keneya', 'environment' => 'Sandbox'],
        'signedDate' => now()->getTimestampMs(),
    ])])->assertOk();
    // Signée par Apple, mais pour une autre application.
    $this->postJson('/api/apple/notifications', ['signedPayload' => FabriqueApple::commune()->jws([
        'notificationType' => 'SUBSCRIBED',
        'data' => ['bundleId' => 'com.autre.application', 'environment' => 'Production', 'signedTransactionInfo' => FabriqueApple::commune()->jws(transactionApple())],
        'signedDate' => now()->getTimestampMs(),
    ])])->assertOk();
    // Production, mais pas l'identifiant App Store de Ngoni Santé.
    $this->postJson('/api/apple/notifications', ['signedPayload' => FabriqueApple::commune()->jws([
        'notificationType' => 'SUBSCRIBED',
        'data' => ['bundleId' => 'com.ismaeldev.keneya', 'appAppleId' => 1, 'environment' => 'Production', 'signedTransactionInfo' => FabriqueApple::commune()->jws(transactionApple())],
        'signedDate' => now()->getTimestampMs(),
    ])])->assertOk();

    expect(DemandeAbonnement::count())->toBe(0)->and(abonnementApple()->formule)->toBe('essai');
});

it('donne le produit App Store de chaque formule au mois et à l’année, et l’uuid à confier à Apple', function () {
    $etat = $this->getJson('/api/abonnement')->assertOk();
    $produits = collect($etat->json('formules'))->mapWithKeys(fn (array $f) => [$f['code'] => collect($f['tarifs'])->pluck('apple_produit', 'mois')->all()]);

    expect($produits['essentiel'])->toBe([1 => 'ngonisante.essentiel.mensuel', 3 => null, 6 => null, 12 => 'ngonisante.essentiel.annuel'])
        ->and($produits['pro'])->toBe([1 => 'ngonisante.pro.mensuel', 3 => null, 6 => null, 12 => 'ngonisante.pro.annuel'])
        ->and($produits['hopital'])->toBe([1 => 'ngonisante.hopital.mensuel', 3 => null, 6 => null, 12 => 'ngonisante.hopital.annuel'])
        ->and($etat->json('apple_compte'))->toBe(strtolower($this->etab->uuid));
});

/** Ngoni Caisse branchée : ses ventes d'abonnement reçues par son contrat console. */
function brancherNgoniCaisse(int $statut = 201): void
{
    config(['sante.caisse.url' => 'https://caisse.exemple.test/api/console/v1/', 'sante.caisse.cle' => 'cle-caisse']);
    Http::fake(['caisse.exemple.test/*' => Http::response(
        $statut === 201 ? ['vente' => ['id' => 'v1', 'numero' => 'V-0042', 'numero_facture' => 'F26-0042']] : ['message' => 'La boutique des ventes d’abonnement n’est pas réglée.'],
        $statut,
    )]);
}

it('enregistre la vente dans la boutique NGONI CAISSE, comme la console pour une demande approuvée', function () {
    brancherNgoniCaisse();
    $this->etab->update(['nom' => 'CSCom de Banconi']);

    acheterApple()->assertOk();
    $demande = DemandeAbonnement::sole();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://caisse.exemple.test/api/console/v1/ventes'
        && $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'Bearer cle-caisse')
        && $r['reference'] === $demande->uuid
        && $r['application'] === 'ngoni-sante'
        && $r['client']['nom'] === 'CSCom de Banconi'
        && $r['client']['telephone'] === $this->admin->fresh()->personne?->telephone
        && $r['client']['adresse'] === 'Ngoni Santé'
        && $r['libelle'] === 'Abonnement Ngoni Santé · Pro · 1 mois · réf. 2000000000000001'
        && $r['montant'] === 15000
        && $r['moyen'] === 'apple_app_store');

    // Chaque renouvellement payé est une vente de plus ; rejouer une transaction, non.
    notifierApple('DID_RENEW', transactionApple(['transactionId' => '2000000000000002', 'transactionReason' => 'RENEWAL', 'expiresDate' => now()->addMonths(2)->getTimestampMs()]))->assertOk();
    acheterApple()->assertOk();
    $renouvellement = DemandeAbonnement::where('apple_transaction_id', '2000000000000002')->sole();
    // Le harnais rejoue à chaque requête ce qui suit les réponses précédentes ;
    // Ngoni Caisse n'enregistre qu'une vente par référence.
    $references = collect(Http::recorded())->map(fn (array $envoi) => $envoi[0]['reference'])->unique()->values()->all();
    expect($references)->toBe([$demande->uuid, $renouvellement->uuid]);
});

it('n’enregistre aucune vente pour un achat de test (Sandbox)', function () {
    brancherNgoniCaisse();

    acheterApple(['environment' => 'Sandbox'])->assertOk();

    expect(DemandeAbonnement::sole()->montant)->toBe(0);
    Http::assertNothingSent();
});

it('prévient l’équipe quand Ngoni Caisse refuse la vente : elle est à saisir à la main', function () {
    brancherNgoniCaisse(422);
    config(['sante.alertes.emails' => 'equipe@exemple.test']);

    acheterApple()->assertOk()->assertJsonPath('abonnement.formule', 'pro');

    $sujets = app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
    expect($sujets)->toContain('Ngoni Santé — Vente à saisir dans Ngoni Caisse : '.$this->etab->nom)
        ->toContain('Ngoni Santé — Abonnement payé sur l’App Store : '.$this->etab->nom);
});
