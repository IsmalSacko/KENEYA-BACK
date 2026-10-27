<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\LectureDossier;
use App\Models\Service;
use App\Models\User;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Un centre de santé et son médecin, connecté. */
function cabinetMedical(string $nom = 'CSCom Hamdallaye'): User
{
    $etab = Etablissement::factory()->create(['nom' => $nom, 'profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $medecin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'medecin', 'name' => 'Dr Diallo']);
    seConnecter($medecin);
    test()->getJson('/api/file/services')->assertOk();

    return $medecin;
}

function seConnecter(User $membre): void
{
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($membre, 'api');
}

/** Une personne inscrite, et son jeton. */
function inscrit(string $tel = '70000077'): string
{
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $token = test()->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => $tel, 'nom' => 'Awa Traoré', 'pin' => '1234',
    ])->json('access_token');
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    return $token;
}

/** Un passage à l'accueil, puis la consultation du médecin. */
function consulterIci(User $medecin, string $telephone, string $diagnostic): string
{
    $accueil = ['etablissement_id' => $medecin->etablissement_id, 'role' => 'accueil'];
    seConnecter(User::where($accueil)->first() ?? User::factory()->create($accueil));
    $visite = test()->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $medecin->etablissement_id)->where('prefixe', 'A')->value('uuid'), 'nom' => 'Awa Traoré', 'telephone' => $telephone,
    ])->assertStatus(201)->json('ticket.visite_uuid');
    seConnecter($medecin);
    test()->postJson("/api/visites/{$visite}/consultation", [
        'motif' => 'Fièvre',
        'constantes' => ['temperature' => 38.5, 'ta_sys' => 120, 'ta_dia' => 80, 'poids' => 62],
        'diagnostic' => $diagnostic,
    ])->assertStatus(201);

    return $visite;
}

function deuxCentres(): array
{
    $patient = inscrit();
    $premier = cabinetMedical('CSCom Hamdallaye');
    consulterIci($premier, '+22370000077', 'Paludisme simple');
    $second = cabinetMedical('Clinique Pasteur');
    $visite = consulterIci($second, '70000077', 'Contrôle');

    return [$patient, $premier, $second, $visite];
}

it('note la consultation dans le dossier du patient, qui la voit dans son app', function () {
    $patient = inscrit();
    $medecin = cabinetMedical();
    $visite = consulterIci($medecin, '+22370000077', 'Paludisme simple');

    $dossier = $this->getJson("/api/visites/{$visite}/dossier")->assertOk()->json('dossier');
    expect($dossier['a_un_compte'])->toBeTrue()
        ->and($dossier['consultations'])->toHaveCount(1)
        ->and($dossier['consultations'][0]['diagnostic'])->toBe('Paludisme simple')
        ->and($dossier['consultations'][0]['constantes']['temperature'])->toEqual(38.5)
        ->and($dossier['consultations'][0]['ici'])->toBeTrue();

    auth()->forgetGuards();
    $mien = $this->withToken($patient)->getJson('/api/compte/dossier')->assertOk()->json();
    expect($mien['consultations'][0]['praticien'])->toBe('Dr Diallo')
        ->and($mien['consultations'][0]['etablissement'])->toBe('CSCom Hamdallaye');
});

it("cache l'historique tenu ailleurs tant que le patient n'a pas donné son accord", function () {
    [, , , $visite] = deuxCentres();

    $dossier = $this->getJson("/api/visites/{$visite}/dossier")->json('dossier');
    expect(collect($dossier['consultations'])->pluck('diagnostic')->all())->toBe(['Contrôle'])
        ->and($dossier['consultations_ailleurs'])->toBe(1)
        ->and($dossier['acces_historique'])->toBeFalse()
        ->and($dossier['peut_demander_acces'])->toBeTrue();
});

it('ouvre l’historique avec le code reçu par SMS, pour 7 jours', function () {
    [, , , $visite] = deuxCentres();
    $uuid = $this->getJson("/api/visites/{$visite}/dossier")->json('dossier.uuid');

    $this->postJson("/api/dossiers/{$uuid}/acces/code")->assertOk();
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    $dernier = end($sms->envoyes);
    expect($dernier['telephone'])->toBe('+22370000077')->and($dernier['message'])->toContain('votre dossier');
    preg_match('/code (\d{6})/', $dernier['message'], $m);

    $this->postJson("/api/dossiers/{$uuid}/acces", ['code' => '000000'])->assertStatus(422);
    $ouvert = $this->postJson("/api/dossiers/{$uuid}/acces", ['code' => $m[1]])->assertOk()->json('dossier');
    expect($ouvert['acces_historique'])->toBeTrue()->and($ouvert['consultations'])->toHaveCount(2);

    $this->travel(8)->days();
    expect($this->getJson("/api/dossiers/{$uuid}")->json('dossier.consultations'))->toHaveCount(1);
});

it("ouvre l'historique en scannant le QR d'accord du patient, une seule fois", function () {
    [$patient, , $second, $visite] = deuxCentres();
    auth()->forgetGuards();
    $qr = $this->withToken($patient)->postJson('/api/compte/dossier/accord')->assertOk()->json('qr');
    expect($qr)->toStartWith('ngoni-sante:accord:');

    seConnecter($second);
    $this->postJson('/api/dossiers/acces-qr', ['qr' => $qr])->assertOk()->assertJsonPath('dossier.acces_historique', true);
    $this->postJson('/api/dossiers/acces-qr', ['qr' => $qr])->assertStatus(422);
    $this->postJson('/api/dossiers/acces-qr', ['qr' => 'ngoni-sante:rdv:x'])->assertStatus(422);
});

it('montre au patient qui a accès et qui a lu, et le laisse retirer un accès', function () {
    [$patient, , $second, $visite] = deuxCentres();
    auth()->forgetGuards();
    $qr = $this->withToken($patient)->postJson('/api/compte/dossier/accord')->json('qr');
    seConnecter($second);
    $uuid = $this->postJson('/api/dossiers/acces-qr', ['qr' => $qr])->json('dossier.uuid');

    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $mien = $this->withToken($patient)->getJson('/api/compte/dossier')->json();
    expect($mien['acces'])->toHaveCount(1)
        ->and($mien['acces'][0]['etablissement'])->toBe('Clinique Pasteur')
        ->and(collect($mien['lectures'])->pluck('etablissement')->unique()->values()->all())->toContain('Clinique Pasteur');

    $this->withToken($patient)->deleteJson("/api/compte/dossier/acces/{$mien['acces'][0]['id']}")->assertOk();
    seConnecter($second);
    expect($this->getJson("/api/dossiers/{$uuid}")->json('dossier.acces_historique'))->toBeFalse();
});

it('trace chaque ouverture du dossier', function () {
    $medecin = cabinetMedical();
    $visite = consulterIci($medecin, '+22370000088', 'Angine');
    $this->getJson("/api/visites/{$visite}/dossier");
    $this->getJson("/api/visites/{$visite}/dossier");

    expect(LectureDossier::count())->toBe(2);
});

it('laisse le patient noter ses allergies, que le médecin voit', function () {
    $patient = inscrit();
    $this->withToken($patient)->patchJson('/api/compte/dossier', ['allergies' => 'Pénicilline', 'groupe_sanguin' => 'O+'])->assertOk();
    $this->withToken($patient)->patchJson('/api/compte/dossier', ['groupe_sanguin' => 'Z'])->assertStatus(422);

    $medecin = cabinetMedical();
    $visite = consulterIci($medecin, '+22370000077', 'Angine');
    expect($this->getJson("/api/visites/{$visite}/dossier")->json('dossier.allergies'))->toBe('Pénicilline');
});

it('garde le secret médical : la caisse ne lit pas le dossier, un autre centre ne le trouve pas', function () {
    $medecin = cabinetMedical();
    $visite = consulterIci($medecin, '+22370000088', 'Angine');
    $uuid = $this->getJson("/api/visites/{$visite}/dossier")->json('dossier.uuid');

    seConnecter(User::factory()->create(['etablissement_id' => $medecin->etablissement_id, 'role' => 'caissier']));
    $this->getJson("/api/visites/{$visite}/dossier")->assertForbidden();

    cabinetMedical('Autre centre');
    $this->getJson("/api/dossiers/{$uuid}")->assertNotFound();
    $this->postJson("/api/dossiers/{$uuid}/acces/code")->assertNotFound(); // pas un 422
    expect($this->getJson('/api/dossiers')->json('data'))->toBe([]);
});

it("garde dans l'établissement le dossier d'un patient sans compte", function () {
    $medecin = cabinetMedical();
    $visite = consulterIci($medecin, '+22370000088', 'Angine');
    $uuid = $this->getJson("/api/visites/{$visite}/dossier")->assertJsonPath('dossier.peut_demander_acces', false)->json('dossier.uuid');

    $this->postJson("/api/dossiers/{$uuid}/acces/code")->assertStatus(422);
    expect($this->getJson('/api/dossiers?q=Awa')->json('data.0.uuid'))->toBe($uuid);
});
