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

it('reconnaît le ticket du patient au scan de l’accueil, et dit quoi faire des autres QR', function () {
    [$etab] = cabinet();
    $service = Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid');
    $ticket = $this->postJson('/api/file/tickets', ['service_uuid' => $service, 'nom' => 'Moussa Coulibaly'])->json('ticket');
    $url = route('ticket.suivi', $ticket['uuid']);

    $this->postJson('/api/accueil/scan', ['qr' => $url])->assertOk()
        ->assertJsonPath('type', 'ticket')->assertJsonPath('ticket.code', $ticket['code']);
    $this->postJson('/api/accueil/scan', ['qr' => 'ngoni-sante:ord:abcdef'])->assertStatus(422)
        ->assertJsonPath('message', 'C’est une ordonnance : elle se scanne à la Pharmacie.');
    $this->postJson('/api/accueil/scan', ['qr' => 'https://exemple.ml'])->assertStatus(422);
});

it('déplace un rendez-vous sur un créneau libre, et prévient le patient', function () {
    [, , $agenda] = cabinet(['horaires' => [['jour' => 1, 'debut' => '08:00', 'fin' => '10:00'], ['jour' => 2, 'debut' => '08:00', 'fin' => '10:00']]]);
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    $corps = ['agenda_uuid' => $agenda, 'nom' => 'Moussa', 'telephone' => '76000001'];
    $rdv = $this->postJson('/api/rdv', [...$corps, 'debut' => '2026-11-02T08:00:00Z'])->json('rendez_vous');
    $this->postJson('/api/rdv', [...$corps, 'nom' => 'Awa', 'debut' => '2026-11-03T09:00:00Z'])->assertStatus(201);

    // Créneau déjà pris : refusé.
    $this->postJson("/api/rdv/{$rdv['uuid']}/deplacer", ['debut' => '2026-11-03T09:00:00Z'])->assertStatus(422);

    $deplace = $this->postJson("/api/rdv/{$rdv['uuid']}/deplacer", ['debut' => '2026-11-03T08:30:00Z'])
        ->assertOk()->json('rendez_vous');
    expect($deplace['jour'])->toBe('2026-11-03')->and($deplace['heure'])->toBe('08:30')
        ->and(collect($sms->envoyes)->last()['message'])->toContain('déplacé au 03/11 à 08:30');
    // L'ancien créneau est libéré.
    expect(collect($this->getJson("/api/agendas/{$agenda}/creneaux?du=2026-11-02&au=2026-11-02")->json('data'))->pluck('heure'))
        ->toContain('08:00');

    $this->postJson("/api/rdv/{$rdv['uuid']}/annuler")->assertOk();
    $this->postJson("/api/rdv/{$rdv['uuid']}/deplacer", ['debut' => '2026-11-03T09:30:00Z'])->assertStatus(422);
});

it('prévient le patient quand la demande est confirmée, refusée ou annulée, avec la raison', function () {
    [, , $agenda] = cabinet(['validation_auto' => false]);
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    $corps = ['agenda_uuid' => $agenda, 'nom' => 'Moussa', 'telephone' => '76000001'];
    $a = $this->postJson('/api/rdv', [...$corps, 'debut' => '2026-11-02T08:00:00Z'])->json('rendez_vous');
    $b = $this->postJson('/api/rdv', [...$corps, 'debut' => '2026-11-02T08:30:00Z'])->json('rendez_vous');

    $this->postJson("/api/rdv/{$a['uuid']}/annuler", ['raison' => 'Médecin en formation'])->assertOk();
    expect(collect($sms->envoyes)->last()['message'])->toContain('annulé (Médecin en formation)')
        ->and(collect($sms->envoyes)->last()['telephone'])->toBe('+22376000001');
    $this->postJson("/api/rdv/{$b['uuid']}/refuser")->assertOk();
    expect(collect($sms->envoyes)->last()['message'])->toContain("n'a pas pu être acceptée");

    // Un rendez-vous clos ne change plus.
    $this->postJson("/api/rdv/{$a['uuid']}/confirmer")->assertStatus(422);
});

it('fixe l’heure d’une demande venue sans heure en la confirmant', function () {
    [$etab, , $agenda] = cabinet();
    $this->postJson('/api/public/rendez-vous', [
        'etablissement_uuid' => $etab->uuid, 'patient_nom' => 'Awa', 'patient_telephone' => '76001122',
        'date_souhaitee' => '2026-11-09T00:00:00Z',
    ])->assertStatus(201);
    $demande = $this->getJson('/api/rdv?du=2026-11-09')->json('data.0');
    expect($demande['creneau'])->toBeFalse();

    // Pas d'agenda sur la demande : il faut le choisir.
    $this->postJson("/api/rdv/{$demande['uuid']}/confirmer", ['debut' => '2026-11-09T08:30:00Z'])->assertStatus(422);
    $confirme = $this->postJson("/api/rdv/{$demande['uuid']}/confirmer", ['debut' => '2026-11-09T08:30:00Z', 'agenda_uuid' => $agenda])
        ->assertOk()->json('rendez_vous');
    expect($confirme['statut'])->toBe('confirme')->and($confirme['creneau'])->toBeTrue()->and($confirme['heure'])->toBe('08:30');
});

it('note l’arrivée le jour même seulement, aussi pour l’accueil sans droit sur les agendas', function () {
    [$etab, , $agenda] = cabinet(['horaires' => [['jour' => 1, 'debut' => '08:00', 'fin' => '10:00'], ['jour' => 2, 'debut' => '08:00', 'fin' => '10:00']]]);
    $demain = $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-03T08:00:00Z', 'nom' => 'Moussa'])->json('rendez_vous');
    $this->postJson("/api/rdv/{$demain['uuid']}/arrivee")->assertStatus(422)
        ->assertJsonPath('message', 'Ce rendez-vous est le 03/11 : l’arrivée se note le jour même.');
    expect(Ticket::count())->toBe(0);

    $jour = $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T08:00:00Z', 'nom' => 'Awa'])->json('rendez_vous');
    $caisse = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'caissier']);
    // Le caissier donne des tickets sans gérer les agendas : il note l'arrivée.
    $this->actingAs($caisse, 'api')->postJson("/api/rdv/{$jour['uuid']}/arrivee")->assertOk()
        ->assertJsonPath('ticket.priorite', 'rdv');
});

it('liste les rendez-vous touchés par une absence, et la retire', function () {
    [, , $agenda] = cabinet();
    $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-09T08:00:00Z', 'nom' => 'Moussa'])->assertStatus(201);

    $reponse = $this->postJson("/api/agendas/{$agenda}/absences", ['du' => '2026-11-09', 'au' => '2026-11-10', 'motif' => 'Congé'])
        ->assertStatus(201)->json();
    expect($reponse['rendez_vous'])->toHaveCount(1)->and($reponse['rendez_vous'][0]['patient_nom'])->toBe('Moussa')
        ->and($reponse['absence']['motif'])->toBe('Congé');
    expect($this->getJson('/api/agendas')->json('data.0.absences'))->toHaveCount(1);

    $this->deleteJson("/api/absences/{$reponse['absence']['id']}")->assertOk();
    expect($this->getJson('/api/agendas')->json('data.0.absences'))->toHaveCount(0);
});

it('refuse des heures d’ouverture qui finissent avant de commencer', function () {
    [, , $agenda] = cabinet();
    $this->patchJson("/api/agendas/{$agenda}", ['horaires' => [['jour' => 1, 'debut' => '14:00', 'fin' => '12:00']]])
        ->assertStatus(422)->assertJsonValidationErrors('horaires.0.fin');
    expect($this->getJson('/api/agendas')->json('data.0.horaires'))->toHaveCount(1);
});

it('met un agenda en pause, et ne le supprime pas avec des rendez-vous à venir', function () {
    [, , $agenda] = cabinet();
    $rdv = $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-09T08:00:00Z', 'nom' => 'Moussa'])->json('rendez_vous');

    $this->patchJson("/api/agendas/{$agenda}", ['actif' => false, 'delai_min_heures' => 4, 'horizon_jours' => 30])->assertOk()
        ->assertJsonPath('agenda.actif', false)->assertJsonPath('agenda.horizon_jours', 30);
    $this->deleteJson("/api/agendas/{$agenda}")->assertStatus(422);

    $this->postJson("/api/rdv/{$rdv['uuid']}/annuler")->assertOk();
    $this->deleteJson("/api/agendas/{$agenda}")->assertOk();
    expect(Agenda::count())->toBe(0);
});

it('retrouve les rendez-vous d’un patient par son nom, sans connaître le jour', function () {
    [, , $agenda] = cabinet();
    $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-09T08:00:00Z', 'nom' => 'Moussa Keita'])->assertStatus(201);
    $this->postJson('/api/rdv', ['agenda_uuid' => $agenda, 'debut' => '2026-11-02T08:00:00Z', 'nom' => 'Awa Traoré'])->assertStatus(201);

    $trouves = $this->getJson('/api/rdv?q=keita')->assertOk()->json('data');
    expect($trouves)->toHaveCount(1)->and($trouves[0]['jour'])->toBe('2026-11-09');
});
