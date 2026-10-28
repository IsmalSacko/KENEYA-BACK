<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\Etablissement;
use App\Models\Lot;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\Service;
use App\Models\User;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use App\Sante\Telephone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Inscrit une personne au Mali et renvoie la réponse JSON. */
function inscrire(string $telephone = '76123456', string $pin = '1234', bool $pro = false): array
{
    return test()->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => $telephone, 'nom' => 'Awa Traoré', 'pin' => $pin, 'pro' => $pro,
    ])->assertStatus(201)->json();
}

/** Ce que Ngoni Santé vérifie avant de rendre l'établissement visible. */
const VERIFICATION = ['autorisation' => 'Arrêté n° 2026-0412/MSDS', 'responsable' => 'Dr Awa Traoré'];

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
    $token = inscrire(pro: true)['access_token'];

    $resp = $this->withToken($token)->postJson('/api/compte/etablissements', [
        'nom' => 'CSCom de Hamdallaye', 'profil' => 'centre_sante', ...VERIFICATION,
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
    $token = inscrire(pro: true)['access_token'];
    $corps = [
        'nom' => 'Pharmacie du Fleuve', 'profil' => 'pharmacie', ...VERIFICATION,
        'uuid' => '6f1c1b0e-6a1f-4a8e-9a55-2f4f7c1d9a01', 'membre_uuid' => '6f1c1b0e-6a1f-4a8e-9a55-2f4f7c1d9a02',
    ];

    $this->withToken($token)->postJson('/api/compte/etablissements', $corps)->assertStatus(201);
    $this->withToken($token)->postJson('/api/compte/etablissements', $corps)->assertOk();

    expect(Etablissement::count())->toBe(1)->and(User::count())->toBe(1);
});

it('ouvre les espaces de chaque établissement de la personne, et pas ceux des autres', function () {
    $connexion = inscrire(pro: true);
    $token = $connexion['access_token'];
    $this->withToken($token)->postJson('/api/compte/etablissements', ['nom' => 'Cabinet A', 'profil' => 'cabinet_medical', ...VERIFICATION]);

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
    $token = inscrire(pro: true)['access_token'];
    $membreUuid = $this->withToken($token)
        ->postJson('/api/compte/etablissements', ['nom' => 'Cabinet', 'profil' => 'cabinet_medical', ...VERIFICATION])
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

it('ajoute un membre à l’équipe : compte créé avec le PIN donné, ou compte existant gardé', function () {
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'pays_code' => 'ML']);
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']);
    $this->actingAs($admin, 'api')->postJson('/api/users', ['name' => 'Seydou Keïta', 'telephone' => '76 55 44 33', 'role' => 'infirmier', 'pin' => '4321'])
        ->assertCreated()->assertJsonPath('compte_existant', false)->assertJsonPath('user.telephone', '+22376554433');
    // Il se connecte tout de suite à Ngoni Santé et trouve son espace.
    auth()->forgetGuards();
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '76554433', 'pin' => '4321'])
        ->assertOk()->assertJsonPath('espaces.0.role', 'infirmier');

    // Quelqu'un qui a déjà son compte le garde, avec son PIN.
    auth()->forgetGuards();
    $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '70111222', 'nom' => 'Awa', 'pin' => '9999']);
    auth()->forgetGuards();
    $this->actingAs($admin, 'api')->postJson('/api/users', ['name' => 'Awa', 'telephone' => '70111222', 'role' => 'caissier', 'pin' => '0000'])
        ->assertCreated()->assertJsonPath('compte_existant', true);
    auth()->forgetGuards();
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '70111222', 'pin' => '9999'])->assertOk()
        ->assertJsonPath('espaces.0.role', 'caissier');
    $this->actingAs($admin, 'api')->postJson('/api/users', ['name' => 'Awa', 'telephone' => '+22370111222', 'role' => 'caissier', 'pin' => '0000'])->assertStatus(409);
});

it('réserve la création d’établissement aux pros, et le cache des patients jusqu’à sa validation', function () {
    // Un patient ne peut pas créer d'établissement.
    $patient = inscrire('70222333')['access_token'];
    $this->withToken($patient)->postJson('/api/compte/etablissements', ['nom' => 'Faux', 'profil' => 'cabinet_medical', ...VERIFICATION])
        ->assertForbidden();
    nouvelleRequete();

    // Un pro le crée : il le configure, mais les patients ne le voient pas encore.
    $pro = inscrire('70444555', pro: true)['access_token'];
    $etab = $this->withToken($pro)->postJson('/api/compte/etablissements', ['nom' => 'Cabinet Nouveau', 'profil' => 'cabinet_medical', ...VERIFICATION])
        ->assertCreated()->json('etablissement');
    nouvelleRequete();
    expect($this->getJson('/api/public/etablissements?q=Nouveau')->json('data'))->toBe([])
        ->and($this->withToken($pro)->getJson('/api/compte')->json('espaces.0.etablissement.valide'))->toBeFalse();

    // Ngoni Santé vérifie, puis valide.
    $this->artisan('sante:etablissements-a-valider')->expectsOutputToContain('Cabinet Nouveau')->assertSuccessful();
    $this->artisan('sante:etablissements-a-valider', ['--valider' => $etab['uuid']])->assertSuccessful();
    expect(collect($this->getJson('/api/public/etablissements?q=Nouveau')->json('data'))->pluck('nom')->all())->toBe(['Cabinet Nouveau']);
});

// ------------------------------------------------------------ ses établissements
it('limite les établissements selon la formule, et dit combien il en reste', function () {
    $token = inscrire(pro: true)['access_token'];
    $creer = fn (string $nom) => $this->withToken($token)->postJson('/api/compte/etablissements', ['nom' => $nom, 'profil' => 'cabinet_medical', ...VERIFICATION]);
    $limite = function () use ($token) {
        nouvelleRequete();

        return $this->withToken($token)->getJson('/api/compte')->json('etablissements');
    };

    // En essai : 2.
    expect($limite())->toBe(['crees' => 0, 'max' => 2, 'restants' => 2]);
    $creer('Cabinet A')->assertCreated();
    nouvelleRequete();
    $creer('Cabinet B')->assertCreated();
    nouvelleRequete();
    $creer('Cabinet C')->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'permet 2 établissements'));

    // Un établissement passe à Pro : 3 ; à Hôpital : 10.
    $a = Etablissement::where('nom', 'Cabinet A')->sole();
    Abonnement::where('etablissement_id', $a->id)->update(['formule' => 'pro', 'fin' => today()->addMonth()]);
    expect($limite())->toBe(['crees' => 2, 'max' => 3, 'restants' => 1]);
    Abonnement::where('etablissement_id', $a->id)->update(['formule' => 'hopital']);
    expect($limite())->toBe(['crees' => 2, 'max' => 10, 'restants' => 8]);

    // Échu, il ne compte plus : on revient au plafond des autres.
    Abonnement::where('etablissement_id', $a->id)->update(['fin' => today()->subDay()]);
    expect($limite()['max'])->toBe(2);

    // Les démonstrations de l'éditeur ne comptent pas dans le nombre, mais
    // leur formule compte pour le plafond.
    $b = Etablissement::where('nom', 'Cabinet B')->sole();
    $b->update(['interne' => true]);
    Abonnement::where('etablissement_id', $b->id)->update(['formule' => 'hopital', 'fin' => null]);
    expect($limite())->toBe(['crees' => 1, 'max' => 10, 'restants' => 9]);
    $creer('Cabinet C')->assertCreated();
});

it('dit avec quelle formule s’ouvre ce que la sienne n’ouvre pas', function () {
    $resp = $this->withToken(inscrire(pro: true)['access_token'])
        ->postJson('/api/compte/etablissements', ['nom' => 'Cabinet', 'profil' => 'centre_sante', ...VERIFICATION])->assertCreated();
    // L'essai ouvre tout.
    expect($resp->json('abonnement.verrous'))->toBe([]);

    $etab = Etablissement::where('uuid', $resp->json('etablissement.uuid'))->sole();
    Abonnement::where('etablissement_id', $etab->id)->update(['formule' => 'essentiel', 'fin' => today()->addMonth()]);
    nouvelleRequete();
    $verrous = $this->withToken($resp->json('access_token'))->getJson('/api/abonnement')->assertOk()->json('abonnement.verrous');
    expect($verrous)->toMatchArray(['labo' => 'Pro', 'maternite' => 'Pro', 'hospitalisation' => 'Hôpital', 'ecrans' => 'Pro', 'rapport' => 'Pro'])
        ->not->toHaveKey('consultation');
});

it('supprime son établissement avec tout ce qu’il contient, sur confirmation du nom', function () {
    $this->artisan('sante:etablissements-demo')->assertSuccessful();
    $admin = $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '73136789', 'pin' => '1234'])->assertOk();
    $token = $admin->json('access_token');
    $cscom = Etablissement::where('profil', 'centre_sante')->sole();
    $autre = Etablissement::where('id', '!=', $cscom->id)->first();
    Etablissement::query()->update(['proprietaire_id' => Personne::where('telephone', '+22373136789')->value('id')]);
    $medecin = Personne::where('telephone', '+22374988201')->sole();
    nouvelleRequete();

    // Un membre qui ne l'a pas créé ne peut pas.
    $tokenMedecin = $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '74988201', 'pin' => '1234'])->json('access_token');
    nouvelleRequete();
    $this->withToken($tokenMedecin)->deleteJson("/api/compte/etablissements/{$cscom->uuid}", ['nom' => $cscom->nom])->assertForbidden();
    nouvelleRequete();

    // Le nom doit être retapé (la casse et les espaces ne comptent pas).
    $this->withToken($token)->deleteJson("/api/compte/etablissements/{$cscom->uuid}", ['nom' => 'CSCom'])->assertStatus(422);
    nouvelleRequete();
    $resp = $this->withToken($token)->deleteJson("/api/compte/etablissements/{$cscom->uuid}", ['nom' => '  '.mb_strtoupper($cscom->nom).' '])->assertOk();

    expect(Etablissement::withTrashed()->find($cscom->id))->toBeNull()
        ->and(User::withTrashed()->where('etablissement_id', $cscom->id)->count())->toBe(0)
        ->and(Service::where('etablissement_id', $cscom->id)->count())->toBe(0)
        ->and(Lot::where('etablissement_id', $cscom->id)->count())->toBe(0)
        ->and(collect($resp->json('espaces'))->pluck('etablissement.uuid'))->not->toContain($cscom->uuid)
        ->and(Etablissement::find($autre->id))->not->toBeNull()
        // Les personnes restent : elles gardent « Ma santé » et leurs autres espaces.
        ->and(Personne::find($medecin->id))->not->toBeNull();
});

it('modifie le nom et les coordonnées de son établissement', function () {
    $token = inscrire(pro: true)['access_token'];
    $resp = $this->withToken($token)->postJson('/api/compte/etablissements', ['nom' => 'Cabinet', 'profil' => 'cabinet_medical', ...VERIFICATION])->assertCreated();
    nouvelleRequete();

    $this->withToken($resp->json('access_token'))->patchJson('/api/etablissements/'.$resp->json('etablissement.id'), [
        'nom' => 'Cabinet Awa', 'telephone' => '+22376000000', 'adresse' => 'Hamdallaye ACI', 'responsable' => 'Dr Awa', 'autorisation' => 'Arrêté 2026-1',
    ])->assertOk()->assertJsonPath('etablissement.nom', 'Cabinet Awa')->assertJsonPath('etablissement.responsable', 'Dr Awa');
    nouvelleRequete();

    expect($this->withToken($token)->getJson('/api/compte')->json('espaces.0'))
        ->toMatchArray(['proprietaire' => true])
        ->and($this->withToken($token)->getJson('/api/compte')->json('espaces.0.etablissement.nom'))->toBe('Cabinet Awa');
});

it('ne laisse pas un autre admin retirer ou couper la personne qui a créé l’établissement', function () {
    $token = inscrire(pro: true)['access_token'];
    $resp = $this->withToken($token)->postJson('/api/compte/etablissements', ['nom' => 'Cabinet', 'profil' => 'cabinet_medical', ...VERIFICATION])->assertCreated();
    $createur = User::where('uuid', $resp->json('user.uuid'))->sole();
    $admin = User::factory()->create(['etablissement_id' => $createur->etablissement_id, 'role' => 'admin_etablissement']);
    nouvelleRequete();

    $this->actingAs($admin, 'api')->patchJson("/api/users/{$createur->id}", ['actif' => false])->assertForbidden();
    $this->actingAs($admin, 'api')->patchJson("/api/users/{$createur->id}", ['role' => 'medecin'])->assertForbidden();
    $this->actingAs($admin, 'api')->deleteJson("/api/users/{$createur->id}")->assertForbidden();
    // Son nom, lui, peut changer.
    $this->actingAs($admin, 'api')->patchJson("/api/users/{$createur->id}", ['name' => 'Dr Awa'])->assertOk();
    expect($createur->fresh()->actif)->toBeTrue();
});
