<?php

declare(strict_types=1);

use App\Models\Agenda;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Personne;
use App\Models\RendezVous;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Lundi 2 novembre 2026, 7 h à Bamako (UTC+0).
    Carbon::setTestNow(Carbon::parse('2026-11-02 07:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/** Un admin de centre de santé connecté, avec un agenda lundi 8 h – 10 h, créneaux de 30 min. */
function cabinet(array $agenda = []): array
{
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null, 'actif' => true]);
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']);
    test()->actingAs($admin, 'api');
    test()->getJson('/api/file/services'); // services de départ

    $a = test()->postJson('/api/agendas', [
        'nom' => 'Dr Diarra',
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'),
        'duree_creneau_min' => 30,
        'horaires' => [['jour' => 1, 'debut' => '08:00', 'fin' => '10:00']],
        ...$agenda,
    ])->assertStatus(201)->json('agenda');

    return [$etab, $admin, $a['uuid']];
}

/** Une personne inscrite, et son jeton. */
function patient(string $tel = '70000099'): string
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

it('calcule les créneaux libres selon les horaires, le délai et les absences', function () {
    [, , $agenda] = cabinet(['delai_min_heures' => 0]);

    $creneaux = $this->getJson("/api/public/agendas/{$agenda}/creneaux?du=2026-11-02&au=2026-11-09")->json('data');
    expect(collect($creneaux)->pluck('heure')->all())->toBe(['08:00', '08:30', '09:00', '09:30', '08:00', '08:30', '09:00', '09:30'])
        ->and($creneaux[0]['jour'])->toBe('2026-11-02');

    // À 8 h 40, les créneaux passés du jour disparaissent.
    Carbon::setTestNow(Carbon::parse('2026-11-02 08:40:00', 'UTC'));
    expect(collect($this->getJson("/api/public/agendas/{$agenda}/creneaux?du=2026-11-02&au=2026-11-02")->json('data'))->pluck('heure')->all())
        ->toBe(['09:00', '09:30']);

    // Une absence ferme la journée.
    $this->postJson("/api/agendas/{$agenda}/absences", ['du' => '2026-11-09', 'au' => '2026-11-09'])->assertStatus(201);
    expect($this->getJson("/api/public/agendas/{$agenda}/creneaux?du=2026-11-09&au=2026-11-09")->json('data'))->toBe([]);
});

it('respecte le nombre de places par créneau', function () {
    [, , $agenda] = cabinet(['places_par_creneau' => 2]);
    $corps = fn (string $nom) => ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T09:00:00Z', 'nom' => $nom, 'telephone' => '76000001'];

    $this->postJson('/api/rdv', $corps('A'))->assertStatus(201);
    $this->postJson('/api/rdv', $corps('B'))->assertStatus(201);
    $this->postJson('/api/rdv', $corps('C'))->assertStatus(422);
});

it('laisse la personne réserver, retrouver et annuler son rendez-vous', function () {
    [, , $agenda] = cabinet();
    $token = patient();

    $rdv = $this->withToken($token)->postJson('/api/compte/rdv', [
        'agenda_uuid' => $agenda, 'debut' => '2026-11-02T09:30:00Z', 'motif' => 'Fièvre',
    ])->assertStatus(201)->json('rendez_vous');

    expect($rdv['statut'])->toBe('confirme')->and($rdv['heure'])->toBe('09:30')->and($rdv['qr'])->toStartWith('ngoni-sante:rdv:');
    expect($this->withToken($token)->getJson('/api/compte/rdv')->json('data.0.uuid'))->toBe($rdv['uuid']);

    $this->withToken($token)->postJson("/api/compte/rdv/{$rdv['uuid']}/annuler")->assertOk()->assertJsonPath('rendez_vous.statut', 'annule');
    expect(collect($this->getJson("/api/public/agendas/{$agenda}/creneaux?du=2026-11-02&au=2026-11-02")->json('data'))->pluck('heure'))
        ->toContain('09:30');
});

it('met la réservation en attente quand l’agenda demande une validation', function () {
    [, , $agenda] = cabinet(['validation_auto' => false]);
    $token = patient();

    $this->withToken($token)->postJson('/api/compte/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T09:00:00Z'])
        ->assertStatus(201)->assertJsonPath('rendez_vous.statut', 'demande');
});

it("fait entrer le patient dans la file, priorité rendez-vous, quand l'accueil scanne son QR", function () {
    [, $admin, $agenda] = cabinet();
    $rdv = $this->postJson('/api/rdv', [
        'agenda_uuid' => $agenda, 'debut' => '2026-11-02T08:30:00Z', 'nom' => 'Moussa', 'telephone' => '76000001',
    ])->json('rendez_vous');

    $reponse = $this->postJson('/api/accueil/scan', ['qr' => $rdv['qr']])->assertOk()->json();
    expect($reponse['ticket']['code'])->toBe('A001')
        ->and($reponse['ticket']['priorite'])->toBe('rdv')
        ->and($reponse['rendez_vous']['statut'])->toBe('honore');

    // Scanner deux fois ne donne pas deux tickets.
    $this->postJson('/api/accueil/scan', ['qr' => $rdv['qr']])->assertOk()->assertJsonPath('ticket.code', 'A001');
    expect(Ticket::count())->toBe(1);
});

it("laisse la personne signaler son arrivée en scannant l'affiche de l'accueil", function () {
    [$etab, , $agenda] = cabinet();
    $qrAccueil = $this->getJson('/api/accueil/qr')->json('qr');
    $token = patient();

    $this->withToken($token)->postJson('/api/compte/arrivee', ['qr' => $qrAccueil])
        ->assertNotFound()->assertJsonPath('code', 'pas_de_rdv');

    $this->withToken($token)->postJson('/api/compte/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T09:00:00Z']);
    $this->withToken($token)->postJson('/api/compte/arrivee', ['qr' => $qrAccueil])
        ->assertOk()->assertJsonPath('ticket.priorite', 'rdv')->assertJsonPath('ticket.etablissement.nom', $etab->nom);
});

it('accueille un rendez-vous à la borne', function () {
    [, $admin, $agenda] = cabinet();
    $rdv = $this->postJson('/api/rdv', [
        'agenda_uuid' => $agenda, 'debut' => '2026-11-02T08:00:00Z', 'nom' => 'Moussa', 'telephone' => '76000001',
    ])->json('rendez_vous');
    $code = $this->postJson('/api/file/appareils', ['mode' => 'borne', 'nom' => 'Borne'])->json('code');
    $jeton = $this->postJson('/api/appareils/appairer', ['code' => $code])->json('jeton');

    $this->postJson('/api/appareil/scan', ['qr' => $rdv['qr']], ['X-Appareil' => $jeton])
        ->assertOk()->assertJsonPath('ticket.code', 'A001');
});

it("identifie le patient par sa carte santé, et l'ancienne carte cesse de marcher", function () {
    cabinet();
    $token = patient();
    $carte = $this->withToken($token)->getJson('/api/compte/carte')->json('qr');
    $nouvelle = $this->withToken($token)->postJson('/api/compte/carte/renouveler')->json('qr');
    expect($nouvelle)->not->toBe($carte);

    $this->actingAs(User::first(), 'api');
    $this->postJson('/api/accueil/scan', ['qr' => $carte])->assertNotFound();
    $reponse = $this->postJson('/api/accueil/scan', [
        'qr' => $nouvelle, 'service_uuid' => Service::where('prefixe', 'C')->value('uuid'),
    ])->assertOk()->json();

    expect($reponse['personne']['nom'])->toBe('Awa Traoré')
        ->and($reponse['ticket']['code'])->toBe('C001')
        ->and(Ticket::first()->personne_id)->toBe(Personne::where('telephone', '+22370000099')->value('id'));
});

it('envoie le rappel de la veille puis celui de dernière minute, une seule fois chacun', function () {
    [, , $agenda] = cabinet(['horaires' => [['jour' => 2, 'debut' => '08:00', 'fin' => '10:00']]]);
    $this->postJson('/api/rdv', [
        'agenda_uuid' => $agenda, 'debut' => '2026-11-03T08:00:00Z', 'nom' => 'Moussa', 'telephone' => '76000001',
    ])->assertStatus(201);
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);

    Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'UTC'));
    $this->artisan('sante:rappels-rdv')->assertSuccessful();
    $this->artisan('sante:rappels-rdv')->assertSuccessful();
    expect($sms->envoyes)->toHaveCount(1)->and($sms->envoyes[0]['message'])->toContain('rappel')->toContain('08:00');

    Carbon::setTestNow(Carbon::parse('2026-11-03 06:30:00', 'UTC'));
    $this->artisan('sante:rappels-rdv')->assertSuccessful();
    expect($sms->envoyes)->toHaveCount(2)->and($sms->envoyes[1]['telephone'])->toBe('+22376000001');
});

it("respecte les droits : l'accueil réserve, le laborantin ne règle pas les agendas", function () {
    [$etab, , $agenda] = cabinet();
    $accueil = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'accueil']);
    $laborantin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'laborantin']);

    $this->actingAs($accueil, 'api')->postJson('/api/rdv', [
        'agenda_uuid' => $agenda, 'debut' => '2026-11-02T09:00:00Z', 'nom' => 'X', 'telephone' => '76000001',
    ])->assertStatus(201);
    $this->actingAs($laborantin, 'api')->getJson('/api/rdv')->assertOk();
    $this->actingAs($laborantin, 'api')->postJson('/api/agendas', ['nom' => 'Y'])->assertForbidden();
    expect(Agenda::count())->toBe(1)->and(RendezVous::count())->toBe(1);
});

it('rattache le rendez-vous au patient : fiche, puis visite à l’arrivée', function () {
    Carbon::setTestNow('2026-11-02 07:00');
    [, , $agenda] = cabinet(['delai_min_heures' => 0]);
    $p = $this->postJson('/api/patients', ['nom' => 'Mariam Sidibé', 'telephone' => '70000044'])->assertStatus(201)->json('patient.uuid');

    $rdv = $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T08:00:00Z', 'dossier_uuid' => $p, 'motif' => 'Contrôle'])
        ->assertStatus(201)->json('rendez_vous');
    expect($rdv['patient_nom'])->toBe('Mariam Sidibé')->and($rdv['dossier_uuid'])->toBe($p);
    expect($this->getJson("/api/patients/{$p}")->json('patient.rendez_vous.0.motif'))->toBe('Contrôle');

    $ticket = $this->postJson("/api/rdv/{$rdv['uuid']}/arrivee")->assertOk()->json('ticket');
    expect(Visite::where('uuid', $ticket['visite_uuid'])->value('dossier_id'))
        ->toBe(Dossier::where('uuid', $p)->value('id'));

    // Sans fiche choisie : le patient est retrouvé ou créé avec le rendez-vous.
    $autre = $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T08:30:00Z', 'nom' => 'Mariam Sidibé', 'telephone' => '70000044'])
        ->assertStatus(201)->json('rendez_vous');
    expect($autre['dossier_uuid'])->toBe($p);
});
