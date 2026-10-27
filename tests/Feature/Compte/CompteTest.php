<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\User;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use App\Sante\Telephone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Inscrit une personne au Mali et renvoie la réponse JSON. */
function inscrire(string $telephone = '76123456', string $pin = '1234'): array
{
    return test()->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => $telephone, 'nom' => 'Awa Traoré', 'pin' => $pin,
    ])->assertStatus(201)->json();
}

/**
 * Comme une nouvelle requête HTTP en production : la librairie JWT garde
 * sinon le dernier jeton émis d'un appel à l'autre pendant le test.
 */
function nouvelleRequete(): void
{
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
}

/** Dernier code SMS envoyé (pilote journal). */
function dernierCode(): string
{
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    preg_match('/\d{6}/', end($sms->envoyes)['message'], $m);

    return $m[0];
}

// ------------------------------------------------------------ référentiel
it('sert les pays actifs avec le Mali par défaut', function () {
    $resp = $this->getJson('/api/pays')->assertOk();

    expect($resp->json('defaut'))->toBe('ML')
        ->and($resp->json('data.0.code'))->toBe('ML')
        ->and($resp->json('data.0.devise'))->toBe('XOF')
        ->and(collect($resp->json('data'))->pluck('code'))->toContain('SN', 'CI', 'GN', 'CM');
});

it('sert les profils avec leurs modules', function () {
    $resp = $this->getJson('/api/profils')->assertOk();
    $pharmacie = collect($resp->json('data'))->firstWhere('code', 'pharmacie');

    expect($pharmacie['modules'])->toBe(['file', 'caisse', 'pharmacie']);
});

// ------------------------------------------------------------ téléphone
it('normalise les numéros au format international', function (string $pays, string $saisi, string $attendu) {
    expect(Telephone::normaliser($saisi, Pays::findOrFail($pays)))->toBe($attendu);
})->with([
    ['ML', '76 12 34 56', '+22376123456'],
    ['ML', '+223 76 12 34 56', '+22376123456'],
    ['ML', '0022376123456', '+22376123456'],
    ['ML', '22376123456', '+22376123456'],
    ['CI', '07 07 12 34 56', '+2250707123456'], // la Côte d'Ivoire garde le 0
    ['GH', '024 412 3456', '+233244123456'],     // le Ghana le retire
]);

// ------------------------------------------------------------ inscription / connexion
it('inscrit une personne sans établissement, prête pour « Ma santé »', function () {
    $resp = inscrire();

    expect($resp['personne']['telephone'])->toBe('+22376123456')
        ->and($resp['espaces'])->toBe([])
        ->and($resp['access_token'])->toBeString();
    $this->assertDatabaseHas('personnes', ['telephone' => '+22376123456', 'pays_code' => 'ML']);
});

it('refuse une deuxième inscription du même numéro, même écrit autrement', function () {
    inscrire('76123456');

    $this->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => '+223 76 12 34 56', 'nom' => 'X', 'pin' => '9999',
    ])->assertStatus(409)->assertJsonPath('code', 'compte_existant');
});

it('exige le code SMS quand la vérification est activée', function () {
    config(['sante.verification_telephone' => true]);

    $this->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => '76123456', 'nom' => 'Awa', 'pin' => '1234',
    ])->assertStatus(422)->assertJsonPath('code', 'code_requis');

    $this->postJson('/api/compte/code', ['pays_code' => 'ML', 'telephone' => '76123456', 'objet' => 'inscription'])
        ->assertStatus(202);

    $this->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => '76123456', 'nom' => 'Awa', 'pin' => '1234', 'code' => dernierCode(),
    ])->assertStatus(201);

    expect(Personne::where('telephone', '+22376123456')->value('telephone_verifie_at'))->not->toBeNull();
});

it('connecte avec le bon PIN et refuse un mauvais', function () {
    inscrire();

    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '76123456', 'pin' => '0000'])
        ->assertStatus(401);
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '76123456', 'pin' => '1234'])
        ->assertOk()->assertJsonPath('personne.nom', 'Awa Traoré');
});

it('bloque le numéro après 5 mauvais PIN', function () {
    inscrire();

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '76123456', 'pin' => '0000']);
    }

    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '76123456', 'pin' => '1234'])
        ->assertStatus(429)->assertJsonPath('code', 'trop_essais');
});

it('change le PIN oublié avec le code SMS', function () {
    inscrire();

    $this->postJson('/api/compte/code', ['pays_code' => 'ML', 'telephone' => '76123456', 'objet' => 'pin'])
        ->assertStatus(202);
    $this->postJson('/api/compte/pin-oublie', [
        'pays_code' => 'ML', 'telephone' => '76123456', 'code' => '000000', 'pin' => '5678',
    ])->assertStatus(422);
    $this->postJson('/api/compte/pin-oublie', [
        'pays_code' => 'ML', 'telephone' => '76123456', 'code' => dernierCode(), 'pin' => '5678',
    ])->assertOk();

    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '76123456', 'pin' => '5678'])
        ->assertOk();
});

// ------------------------------------------------------------ espaces pro
it('crée un établissement : la personne en devient admin, avec la devise de son pays', function () {
    $token = inscrire()['access_token'];

    $resp = $this->withToken($token)->postJson('/api/compte/etablissements', [
        'nom' => 'CSCom de Hamdallaye', 'profil' => 'centre_sante',
    ])->assertStatus(201);

    expect($resp->json('user.role'))->toBe('admin_etablissement')
        ->and($resp->json('etablissement.devise'))->toBe('XOF')
        ->and($resp->json('etablissement.type'))->toBe('cabinet_pharmacie') // lu par l'app 3.x
        ->and($resp->json('etablissement.modules'))->toContain('file', 'pharmacie', 'labo');

    // Le jeton du membre ouvre les routes pro existantes.
    $this->withToken($resp->json('access_token'))->getJson('/api/me')
        ->assertOk()->assertJsonPath('user.role', 'admin_etablissement');
});

it("rejoue la création d'établissement hors ligne sans doublon", function () {
    $token = inscrire()['access_token'];
    $corps = [
        'nom' => 'Pharmacie du Fleuve', 'profil' => 'pharmacie',
        'uuid' => '6f1c1b0e-6a1f-4a8e-9a55-2f4f7c1d9a01', 'membre_uuid' => '6f1c1b0e-6a1f-4a8e-9a55-2f4f7c1d9a02',
    ];

    $this->withToken($token)->postJson('/api/compte/etablissements', $corps)->assertStatus(201);
    $this->withToken($token)->postJson('/api/compte/etablissements', $corps)->assertOk();

    expect(Etablissement::count())->toBe(1)->and(User::count())->toBe(1);
});

it('ouvre les espaces de chaque établissement de la personne, et pas ceux des autres', function () {
    $connexion = inscrire();
    $token = $connexion['access_token'];
    $this->withToken($token)->postJson('/api/compte/etablissements', ['nom' => 'Cabinet A', 'profil' => 'cabinet_medical']);

    // Un admin d'un autre établissement ajoute la même personne comme médecin.
    $autre = Etablissement::factory()->create();
    $admin = User::factory()->create(['etablissement_id' => $autre->id, 'role' => 'admin_etablissement']);
    $this->actingAs($admin, 'api')->postJson('/api/users', [
        'name' => 'Awa Traoré', 'telephone' => '76123456', 'role' => 'medecin', 'pin' => '4321',
    ])->assertStatus(201);
    nouvelleRequete();

    $espaces = $this->withToken($token)->getJson('/api/compte')->assertOk()->json('espaces');
    expect(collect($espaces)->pluck('role')->sort()->values()->all())->toBe(['admin_etablissement', 'medecin']);

    $medecin = collect($espaces)->firstWhere('role', 'medecin');
    $this->withToken($token)->postJson('/api/compte/espaces/'.$medecin['membre_uuid'])
        ->assertOk()->assertJsonPath('user.role', 'medecin');

    // Le membre d'une autre personne n'est pas un espace à elle.
    $this->withToken($token)->postJson('/api/compte/espaces/'.$admin->uuid)->assertNotFound();
});

it("n'ouvre pas les routes pro avec le jeton d'une personne", function () {
    $token = inscrire()['access_token'];

    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

it('refuse un espace dont le membre est désactivé', function () {
    $token = inscrire()['access_token'];
    $membreUuid = $this->withToken($token)
        ->postJson('/api/compte/etablissements', ['nom' => 'Cabinet', 'profil' => 'cabinet_medical'])
        ->json('user.uuid');
    User::where('uuid', $membreUuid)->update(['actif' => false]);
    nouvelleRequete();

    $this->withToken($token)->postJson('/api/compte/espaces/'.$membreUuid)->assertForbidden();
});

// ------------------------------------------------------------ compatibilité 3.x
it("rattache à une personne le membre créé par l'inscription 3.x", function () {
    $this->postJson('/api/register', [
        'nom_etablissement' => 'Cabinet 3.x', 'type' => 'pharmacie', 'name' => 'Moussa', 'telephone' => '66001122', 'pin' => '1234',
    ])->assertStatus(201);

    $membre = User::where('telephone', '66001122')->firstOrFail();
    expect($membre->personne?->telephone)->toBe('+22366001122')
        ->and($membre->etablissement?->profil)->toBe('pharmacie');

    // Le même PIN ouvre le compte Ngoni Santé.
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '66001122', 'pin' => '1234'])
        ->assertOk()->assertJsonCount(1, 'espaces');
});

it('reprend les membres existants : un numéro partagé donne une seule personne', function () {
    $etabA = Etablissement::factory()->create();
    $etabB = Etablissement::factory()->create();
    foreach ([[$etabA, 'Ancien nom', now()->subDay()], [$etabB, 'Nom récent', now()]] as [$etab, $nom, $date]) {
        DB::table('users')->insert([
            'uuid' => (string) Str::uuid(), 'etablissement_id' => $etab->id, 'name' => $nom, 'telephone' => '76990011',
            'role' => 'medecin', 'pin' => bcrypt('1234'), 'actif' => true, 'created_at' => $date, 'updated_at' => $date,
        ]);
    }

    $migration = require database_path('migrations/2026_10_26_100100_create_personnes_and_profils.php');
    (fn () => $this->reprendrePersonnes())->call($migration);

    $personne = Personne::where('telephone', '+22376990011')->sole();
    expect($personne->nom)->toBe('Nom récent')
        ->and(User::where('telephone', '76990011')->pluck('personne_id')->unique()->all())->toBe([$personne->id]);
});

it('expose le profil dans la recherche publique et filtre dessus', function () {
    Etablissement::factory()->create(['nom' => 'Officine', 'profil' => 'pharmacie', 'type' => null, 'actif' => true]);
    Etablissement::factory()->create(['nom' => 'CSCom', 'profil' => 'centre_sante', 'type' => null, 'actif' => true]);

    $resp = $this->getJson('/api/public/etablissements?profil=pharmacie')->assertOk();

    expect($resp->json('data'))->toHaveCount(1)
        ->and($resp->json('data.0.profil_label'))->toBe('Pharmacie')
        ->and($resp->json('data.0.devise'))->toBe('XOF');
});

it('publie la politique de confidentialité', function () {
    $this->get('/confidentialite')->assertOk()->assertSee('Politique de confidentialité')->assertSee('suppression-compte');
});
