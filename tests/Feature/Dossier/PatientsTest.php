<?php

declare(strict_types=1);

use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Service;
use App\Models\User;
use App\Models\Visite;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
