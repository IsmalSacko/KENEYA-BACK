<?php

declare(strict_types=1);

use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Personne;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Dossier\Dossiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function membreCentre(Etablissement $etab, string $role): User
{
    $m = User::where(['etablissement_id' => $etab->id, 'role' => $role])->first()
        ?? User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role, 'name' => ucfirst($role)]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($m, 'api');

    return $m;
}

function centreAccueil(): Etablissement
{
    $etab = Etablissement::factory()->create(['nom' => 'CSCom Banconi', 'profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    membreCentre($etab, 'accueil');
    test()->getJson('/api/file/services')->assertOk();

    return $etab;
}

function envoyerEnConsultation(Etablissement $etab, string $patient): array
{
    return test()->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'),
        'dossier_uuid' => $patient,
    ])->assertStatus(201)->json('ticket');
}

it('crée le patient à l’accueil, avec un âge estimé, sans doublon', function () {
    centreAccueil();
    $p = $this->postJson('/api/patients', [
        'nom' => 'Ousmane Diarra', 'sexe' => 'M', 'age' => 34, 'telephone' => '76 12 34 56', 'adresse' => 'Banconi',
    ])->assertStatus(201)->json('patient');

    expect($p['telephone'])->toBe('+22376123456')
        ->and($p['age'])->toBe(34)
        ->and($p['naissance_estimee'])->toBeTrue()
        ->and($p)->not->toHaveKey('allergies');

    $encore = $this->postJson('/api/patients', ['nom' => 'ousmane  DIARRA', 'telephone' => '76123456'])
        ->assertOk()->assertJsonPath('existant', true)->json('patient');
    expect($encore['uuid'])->toBe($p['uuid']);

    // Son enfant, amené avec le même téléphone : un autre patient.
    $enfant = $this->postJson('/api/patients', ['nom' => 'Aïcha Diarra', 'age' => 3, 'telephone' => '76123456'])
        ->assertStatus(201)->json('patient');
    expect($enfant['uuid'])->not->toBe($p['uuid'])->and(Dossier::count())->toBe(2);
});

it('envoie le patient en consultation : ticket et visite dans sa fiche', function () {
    $etab = centreAccueil();
    $p = $this->postJson('/api/patients', ['nom' => 'Ousmane Diarra', 'age' => 34])->json('patient.uuid');
    $t = envoyerEnConsultation($etab, $p);

    expect(Visite::where('uuid', $t['visite_uuid'])->value('dossier_id'))->toBe(Dossier::where('uuid', $p)->value('id'))
        ->and($t['nom'])->toBe('Ousmane Diarra');
    // Revenu à la caisse puis au labo le même jour : la même visite.
    $labo = $this->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'L')->value('uuid'), 'dossier_uuid' => $p,
    ])->json('ticket');
    expect($labo['visite_uuid'])->toBe($t['visite_uuid']);

    $fiche = $this->getJson("/api/patients/{$p}")->assertOk()->json('patient');
    expect($fiche['visites'])->toHaveCount(1)
        ->and($fiche['visites'][0]['services'])->toBe(['Consultation', 'Laboratoire']);
    expect($this->getJson('/api/patients?q=ousm')->json('data.0.uuid'))->toBe($p);
});

it('fait passer par le tri : le médecin retrouve les constantes', function () {
    $etab = centreAccueil();
    $p = $this->postJson('/api/patients', ['nom' => 'Ousmane Diarra', 'age' => 34])->json('patient.uuid');
    $visite = envoyerEnConsultation($etab, $p)['visite_uuid'];

    membreCentre($etab, 'infirmier');
    $this->postJson("/api/visites/{$visite}/tri", ['constantes' => ['temperature' => 39.2, 'ta_sys' => 110, 'ta_dia' => 70, 'poids' => 70]])
        ->assertOk()->assertJsonPath('tri.par', 'Infirmier');

    membreCentre($etab, 'medecin');
    $dossier = $this->getJson("/api/visites/{$visite}/dossier")->assertOk()->json();
    expect($dossier['tri']['constantes']['temperature'])->toEqual(39.2)
        ->and($dossier['dossier']['uuid'])->toBe($p);
    $c = $this->postJson("/api/visites/{$visite}/consultation", ['motif' => 'Fièvre', 'diagnostic' => 'Paludisme'])
        ->assertStatus(201)->json('consultation');
    expect($c['constantes']['temperature'])->toEqual(39.2);

    // Le tri se voit sur la visite (soignants) et dans le dossier ouvert depuis la fiche.
    expect($this->getJson("/api/visites/{$visite}")->json('visite.tri.constantes.ta_sys'))->toBe(110)
        ->and($this->getJson("/api/dossiers/{$p}")->json('tri.par'))->toBe('Infirmier');
    membreCentre($etab, 'caissier');
    expect($this->getJson("/api/visites/{$visite}")->json('visite.tri'))->toBeNull();

    // L'accueil ne fait pas le tri.
    membreCentre($etab, 'accueil');
    $this->postJson("/api/visites/{$visite}/tri", ['constantes' => ['poids' => 70]])->assertForbidden();
});

it('relie le patient à son compte Ngoni Santé quand le nom et le numéro concordent', function () {
    auth()->forgetGuards();
    $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '70000011', 'nom' => 'Awa Traoré', 'pin' => '1234']);
    centreAccueil();

    $p = $this->postJson('/api/patients', ['nom' => 'Awa Traore', 'telephone' => '70000011'])->json('patient');
    expect($p['a_un_compte'])->toBeTrue();
});

it('garde les patients d’un centre à l’abri des autres', function () {
    centreAccueil();
    $p = $this->postJson('/api/patients', ['nom' => 'Ousmane Diarra'])->json('patient.uuid');

    $autre = centreAccueil();
    $this->getJson("/api/patients/{$p}")->assertNotFound();
    $this->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $autre->id)->where('prefixe', 'A')->value('uuid'), 'dossier_uuid' => $p,
    ])->assertNotFound();
    expect($this->getJson('/api/patients')->json('data'))->toBe([]);
});

it('fonctionne sans SMS : accord sur place, nouveau PIN par l’accueil', function () {
    config(['sante.sms.actif' => false]);
    $this->getJson('/api/options')->assertOk()->assertJsonPath('sms', false);

    auth()->forgetGuards();
    $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '70000012', 'nom' => 'Awa Traoré', 'pin' => '1234']);
    $this->postJson('/api/compte/code', ['pays_code' => 'ML', 'telephone' => '70000012', 'objet' => 'pin'])
        ->assertStatus(422)->assertJsonPath('message', 'Les SMS ne partent pas encore : demandez un nouveau PIN à l’accueil de votre centre de santé.');

    $etab = centreAccueil();
    $p = $this->postJson('/api/patients', ['nom' => 'Awa Traoré', 'telephone' => '70000012'])->json('patient.uuid');
    $pin = $this->postJson("/api/patients/{$p}/nouveau-pin")->assertOk()->json('pin');
    expect($pin)->toMatch('/^\d{4}$/');
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '70000012', 'pin' => $pin])->assertOk();

    // L'accord « patient présent » exige une visite ouverte aujourd'hui.
    membreCentre($etab, 'accueil');
    $absent = $this->postJson('/api/patients', ['nom' => 'Moussa Keïta'])->json('patient.uuid');
    envoyerEnConsultation($etab, $p);
    membreCentre($etab, 'medecin');
    $this->postJson("/api/dossiers/{$p}/acces/code")->assertStatus(422);
    $this->postJson("/api/dossiers/{$absent}/acces/sur-place")->assertStatus(422);
    $this->postJson("/api/dossiers/{$p}/acces/sur-place")->assertOk()->assertJsonPath('dossier.acces_historique', true);
});

it('retrouve le patient par ses mots dans n’importe quel ordre, ou par un bout de son numéro', function () {
    centreAccueil();
    $this->postJson('/api/patients', ['nom' => 'Ousmane Diaby', 'telephone' => '06 03 06 06'])->assertStatus(201);
    $this->postJson('/api/patients', ['nom' => 'Awa Traoré'])->assertStatus(201);

    $noms = fn (string $q): array => array_column($this->getJson('/api/patients?q='.urlencode($q))->json('data'), 'nom');
    expect($noms('diaby ousmane'))->toBe(['Ousmane Diaby'])
        ->and($noms('ous dia'))->toBe(['Ousmane Diaby'])
        ->and($noms('0306'))->toBe(['Ousmane Diaby'])
        ->and($noms('awa diaby'))->toBe([]);
});

it('liste les dossiers avec leur numéro, la dernière visite et qui est là aujourd’hui', function () {
    $etab = centreAccueil();
    $p = $this->postJson('/api/patients', ['nom' => 'Ousmane Diaby'])->json('patient');
    $this->postJson('/api/patients', ['nom' => 'Awa Traoré']);
    envoyerEnConsultation($etab, $p['uuid']);

    $liste = collect($this->getJson('/api/patients')->json('data'))->keyBy('nom');
    expect($liste['Ousmane Diaby']['numero'])->toMatch('/^'.now()->format('y').'-\d{4}$/')
        ->and($liste['Ousmane Diaby']['en_cours'])->toBeTrue()
        ->and($liste['Ousmane Diaby']['derniere_visite'])->not->toBeNull()
        ->and($liste['Awa Traoré']['en_cours'])->toBeFalse()
        ->and($liste['Awa Traoré']['derniere_visite'])->toBeNull();
    expect(array_column($this->getJson('/api/patients?q='.$liste['Ousmane Diaby']['numero'])->json('data'), 'nom'))->toBe(['Ousmane Diaby']);
});

it('donne à chaque patient un numéro du centre, au hasard, qui ne change plus', function () {
    centreAccueil();
    $numeros = [];
    for ($i = 0; $i < 30; $i++) {
        $numeros[] = $this->postJson('/api/patients', ['nom' => "Patient {$i}"])->json('patient.numero');
    }
    // Tous différents, et pas à la suite.
    expect(array_unique($numeros))->toHaveCount(30)
        ->and($numeros)->not->toBe(collect($numeros)->sort()->values()->all());

    $un = Dossier::where('nom', 'Patient 3')->value('uuid');
    $this->getJson("/api/patients/{$un}")->assertJsonPath('patient.numero', $numeros[3]);
    $this->getJson('/api/patients?q='.$numeros[3])->assertJsonPath('data.0.nom', 'Patient 3');
});

it('numérote les patients déjà enregistrés, avec l’année de leur première venue', function () {
    $etab = centreAccueil();
    $p = $this->postJson('/api/patients', ['nom' => 'Ancien Patient'])->json('patient');
    $id = Dossier::where('uuid', $p['uuid'])->value('id');
    Dossier::whereKey($id)->update(['created_at' => '2024-03-01 10:00:00']);
    DB::table('numeros_dossier')->delete();

    $migration = require database_path('migrations/2026_10_28_110000_numeros_de_dossier.php');
    (fn () => $this->numeroterLesPatientsDejaLa())->call($migration);

    expect(DB::table('numeros_dossier')->where('dossier_id', $id)->where('etablissement_id', $etab->id)->value('numero'))
        ->toMatch('/^24-\d{4}$/');
});

it('fusionne deux fiches de la même personne : visites, numéro et informations rangés sur celle qu’on garde', function () {
    $etab = centreAccueil();
    $bon = $this->postJson('/api/patients', ['nom' => 'Ousmane Diaby', 'telephone' => '76001234', 'adresse' => 'Kalaban'])->json('patient');
    $doublon = $this->postJson('/api/patients', ['nom' => 'ouss', 'sexe' => 'M', 'age' => 35])->json('patient');
    envoyerEnConsultation($etab, $doublon['uuid']);

    $fiche = $this->postJson("/api/patients/{$bon['uuid']}/fusionner", ['doublon_uuid' => $doublon['uuid']])->assertOk()->json('patient');
    expect($fiche['numero'])->toBe($bon['numero'])
        ->and($fiche['sexe'])->toBe('M')
        ->and($fiche['visites'])->toHaveCount(1)
        ->and(Dossier::where('uuid', $doublon['uuid'])->exists())->toBeFalse();
    $this->postJson("/api/patients/{$bon['uuid']}/fusionner", ['doublon_uuid' => $bon['uuid']])->assertStatus(422);
});

it('relie la fiche du centre au compte du patient qui montre sa carte, même s’il a déjà un dossier ailleurs', function () {
    // Un autre centre le connaît déjà par son compte.
    auth()->forgetGuards();
    $jeton = $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '70000077', 'nom' => 'Ousmane Diaby', 'pin' => '1234'])->json('access_token');
    $carte = $this->withToken($jeton)->getJson('/api/compte/carte')->json('qr');
    $compte = Personne::where('telephone', '+22370000077')->sole();
    $dossierCompte = app(Dossiers::class)->pourPersonne($compte);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    // Ici, l'accueil lui avait fait une fiche sans le savoir (autre numéro).
    $etab = centreAccueil();
    $fiche = $this->postJson('/api/patients', ['nom' => 'ouss', 'sexe' => 'M', 'age' => 35])->json('patient');
    envoyerEnConsultation($etab, $fiche['uuid']);

    $p = $this->postJson("/api/patients/{$fiche['uuid']}/relier", ['qr' => $carte])->assertOk()->json('patient');
    expect($p['uuid'])->toBe($dossierCompte->uuid)
        ->and($p['a_un_compte'])->toBeTrue()
        ->and($p['numero'])->toBe($fiche['numero'])
        ->and($p['visites'])->toHaveCount(1)
        ->and(Dossier::where('uuid', $fiche['uuid'])->exists())->toBeFalse();
    $this->postJson("/api/patients/{$p['uuid']}/relier", ['qr' => 'ngoni-sante:carte:faux1234'])->assertStatus(422);
});

it('ne donne pas un second ticket au patient qui a déjà pris le sien depuis l’app', function () {
    $etab = centreAccueil();
    $service = Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid');
    Service::where('uuid', $service)->update(['ticket_distance' => true]);

    // Depuis chez lui, il prend son ticket.
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $jeton = $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '70000088', 'nom' => 'Moussa Keïta', 'pin' => '1234'])->json('access_token');
    $app = $this->withToken($jeton)->postJson('/api/compte/tickets', ['service_uuid' => $service])->assertCreated()->json('ticket');
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    // À l'accueil, on le retrouve et on « l'envoie en consultation » : c'est le même ticket.
    membreCentre($etab, 'accueil');
    $fiche = $this->postJson('/api/patients', ['nom' => 'Moussa Keïta', 'telephone' => '70000088'])->json('patient');
    $accueil = envoyerEnConsultation($etab, $fiche['uuid']);
    expect($accueil['code'])->toBe($app['code'])
        ->and(Ticket::where('service_id', Service::where('uuid', $service)->value('id'))->count())->toBe(1)
        ->and(Visite::where('etablissement_id', $etab->id)->count())->toBe(1);
});
