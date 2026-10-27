<?php

declare(strict_types=1);

use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Service;
use App\Models\User;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function membreMaternite(Etablissement $etab, string $role): User
{
    $m = User::where(['etablissement_id' => $etab->id, 'role' => $role])->first()
        ?? User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role, 'name' => ucfirst($role)]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($m, 'api');

    return $m;
}

/** Une maternité, une femme reçue à l'accueil, la sage-femme connectée. */
function maternite(string $telephone = '+22370000066'): array
{
    $etab = Etablissement::factory()->create(['nom' => 'CSRéf Commune V', 'profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    membreMaternite($etab, 'accueil');
    test()->getJson('/api/file/services')->assertOk();
    $visite = test()->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'M')->value('uuid'),
        'nom' => 'Aminata Coulibaly', 'telephone' => $telephone,
    ])->assertStatus(201)->json('ticket.visite_uuid');
    membreMaternite($etab, 'sage_femme');

    return [$etab, $visite];
}

function inscrite(string $tel = '70000066'): string
{
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $token = test()->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => $tel, 'nom' => 'Aminata Coulibaly', 'pin' => '1234',
    ])->json('access_token');
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    return $token;
}

it('déclare la grossesse et calcule le terme et les semaines', function () {
    Carbon::setTestNow('2026-10-27 10:00');
    [, $visite] = maternite();

    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => '2026-07-01', 'gestite' => 3, 'parite' => 2])
        ->assertStatus(201)->json('grossesse');

    expect($g['dpa'])->toBe('2027-04-07')
        ->and($g['semaines'])->toBe(16)
        ->and($this->getJson("/api/visites/{$visite}/grossesse")->json('grossesse.uuid'))->toBe($g['uuid']);
    $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => '2026-07-01'])->assertStatus(422);
});

it('note les CPN, numérotées, avec l’âge de la grossesse', function () {
    Carbon::setTestNow('2026-10-27 10:00');
    [, $visite] = maternite();
    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => '2026-07-01'])->json('grossesse.uuid');

    $this->postJson("/api/maternite/grossesses/{$g}/cpn", ['poids' => 62.5, 'ta_sys' => 110, 'ta_dia' => 70, 'tpi' => 1, 'vat' => 1, 'fer' => true, 'moustiquaire' => true])
        ->assertStatus(201);
    Carbon::setTestNow('2026-11-24 10:00');
    $suivi = $this->postJson("/api/maternite/grossesses/{$g}/cpn", ['poids' => 64, 'hauteur_uterine' => 22, 'bcf' => 140, 'prochain_rdv' => '2026-12-22'])
        ->assertStatus(201)->json('grossesse');

    expect(collect($suivi['cpn'])->pluck('numero')->all())->toBe([1, 2])
        ->and(collect($suivi['cpn'])->pluck('semaines')->all())->toBe([16, 20])
        ->and($suivi['prochain_rdv'])->toBe('2026-12-22');
    $this->postJson("/api/maternite/grossesses/{$g}/cpn", ['presentation' => 'debout'])->assertStatus(422);
});

it('ouvre un dossier à chaque enfant né vivant, rattaché à sa mère', function () {
    [, $visite] = maternite();
    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => now()->subDays(275)->toDateString()])->json('grossesse.uuid');

    $fin = $this->postJson("/api/maternite/grossesses/{$g}/accouchement", [
        'date_heure' => now()->subHour()->toIso8601String(), 'mode' => 'voie_basse',
        'enfants' => [
            ['sexe' => 'F', 'poids_g' => 3100, 'apgar1' => 8, 'apgar5' => 10, 'prenom' => 'Awa'],
            ['sexe' => 'M', 'poids_g' => 2600, 'apgar1' => 7, 'apgar5' => 9],
        ],
    ])->assertStatus(201)->json('grossesse');

    expect($fin['statut'])->toBe('accouchee')
        ->and(collect($fin['accouchement']['enfants'])->pluck('nom')->all())->toBe(['Awa', 'Bébé 2 de Aminata Coulibaly'])
        ->and(Dossier::whereNotNull('responsable_id')->count())->toBe(2);
    $this->postJson("/api/maternite/grossesses/{$g}/cpn", ['poids' => 60])->assertStatus(422);
    expect($this->getJson('/api/maternite/grossesses')->json('data'))->toBe([]);
});

it('tient le carnet de vaccination : fait, à faire, en retard', function () {
    [, $visite] = maternite();
    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => now()->subDays(305)->toDateString()])->json('grossesse.uuid');
    $enfant = $this->postJson("/api/maternite/grossesses/{$g}/accouchement", [
        'date_heure' => now()->subDays(60)->toIso8601String(), 'mode' => 'cesarienne', 'enfants' => [['sexe' => 'F']],
    ])->json('grossesse.accouchement.enfants.0.dossier_uuid');

    $carnet = collect($this->postJson("/api/dossiers/{$enfant}/vaccins", ['vaccin' => 'BCG', 'fait_le' => now()->subDays(60)->toDateString(), 'lot' => 'B123'])
        ->assertOk()->json('carnet'))->keyBy('code');

    expect($carnet['BCG']['fait_le'])->not->toBeNull()
        ->and($carnet['BCG']['en_retard'])->toBeFalse()
        ->and($carnet['VPO0']['en_retard'])->toBeTrue()       // naissance, pas fait
        ->and($carnet['PENTA1']['en_retard'])->toBeTrue()     // 6 semaines passées
        ->and($carnet['PENTA2']['en_retard'])->toBeFalse();   // 10 semaines : pas encore
    $this->postJson("/api/dossiers/{$enfant}/vaccins", ['vaccin' => 'XYZ'])->assertStatus(422);

    // L'infirmier vaccine aussi.
    [$etab] = [Etablissement::where('nom', 'CSRéf Commune V')->first()];
    membreMaternite($etab, 'infirmier');
    $this->postJson("/api/dossiers/{$enfant}/vaccins", ['vaccin' => 'VPO0'])->assertOk();
});

it('montre à la mère sa grossesse et le carnet de ses enfants', function () {
    $token = inscrite();
    [, $visite] = maternite();
    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => now()->subDays(100)->toDateString()])->json('grossesse.uuid');
    $this->postJson("/api/maternite/grossesses/{$g}/cpn", ['poids' => 60, 'prochain_rdv' => now()->addDays(30)->toDateString()]);

    auth()->forgetGuards();
    $moi = $this->withToken($token)->getJson('/api/compte/maternite')->assertOk()->json();
    expect($moi['grossesse']['semaines'])->toBe(14)->and($moi['grossesse']['cpn'])->toHaveCount(1)->and($moi['enfants'])->toBe([]);

    membreMaternite(Etablissement::where('nom', 'CSRéf Commune V')->first(), 'sage_femme');
    $this->postJson("/api/maternite/grossesses/{$g}/accouchement", [
        'date_heure' => now()->toIso8601String(), 'mode' => 'voie_basse', 'enfants' => [['sexe' => 'M', 'prenom' => 'Moussa']],
    ])->assertStatus(201);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $moi = $this->withToken($token)->getJson('/api/compte/maternite')->json();
    expect($moi['grossesse'])->toBeNull()
        ->and($moi['enfants'][0]['nom'])->toBe('Moussa')
        ->and($moi['enfants'][0]['carnet'])->toHaveCount(19);
});

it('réserve le suivi à la maternité : ni la caisse, ni un autre centre', function () {
    [$etab, $visite] = maternite();
    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => now()->subDays(100)->toDateString()])->json('grossesse.uuid');

    membreMaternite($etab, 'caissier');
    $this->getJson("/api/maternite/grossesses/{$g}")->assertForbidden();

    $autre = Etablissement::factory()->create(['profil' => 'maternite', 'type' => null, 'modules' => null]);
    membreMaternite($autre, 'sage_femme');
    $this->getJson("/api/maternite/grossesses/{$g}")->assertNotFound();
});

it('rappelle par SMS la CPN de demain et les vaccins prévus, une seule fois', function () {
    [, $visite] = maternite();
    $g = $this->postJson("/api/visites/{$visite}/grossesse", ['ddr' => now()->subDays(305)->toDateString()])->json('grossesse.uuid');
    $this->postJson("/api/maternite/grossesses/{$g}/cpn", ['poids' => 60, 'prochain_rdv' => now()->addDay()->toDateString()])->assertStatus(201);

    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    $this->artisan('sante:rappels-maternite')->assertSuccessful();
    $this->artisan('sante:rappels-maternite')->assertSuccessful();
    $cpn = collect($sms->envoyes)->filter(fn ($s) => str_contains($s['message'], 'prénatale'));
    expect($cpn)->toHaveCount(1)->and($cpn->first()['telephone'])->toBe('+22370000066');

    // Un enfant né il y a 41 jours : Penta 1 et ses compagnons, demain.
    $this->postJson("/api/maternite/grossesses/{$g}/accouchement", [
        'date_heure' => now()->subDays(41)->toIso8601String(), 'mode' => 'voie_basse', 'enfants' => [['sexe' => 'M', 'prenom' => 'Moussa']],
    ])->assertStatus(201);
    $this->artisan('sante:rappels-maternite')->assertSuccessful();
    $vaccins = collect($sms->envoyes)->filter(fn ($s) => str_contains($s['message'], 'Moussa'));
    expect($vaccins)->toHaveCount(1)->and($vaccins->first()['message'])->toContain('Pentavalent 1')
        ->and($vaccins->first()['telephone'])->toBe('+22370000066');
});
