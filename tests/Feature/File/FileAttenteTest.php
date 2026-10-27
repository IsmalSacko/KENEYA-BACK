<?php

declare(strict_types=1);

use App\Models\Appareil;
use App\Models\Etablissement;
use App\Models\Personne;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Un membre connecté d'un centre de santé, et ses services par défaut. */
function equipe(string $role = 'admin_etablissement', string $profil = 'centre_sante'): User
{
    $etab = Etablissement::factory()->create(['profil' => $profil, 'type' => null, 'modules' => null]);
    $membre = User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role]);
    test()->actingAs($membre, 'api');
    test()->getJson('/api/file/services')->assertOk();

    return $membre;
}

function serviceUuid(string $prefixe): string
{
    return Service::where('prefixe', $prefixe)->value('uuid');
}

function ticket(string $prefixe = 'A', string $priorite = 'ordinaire', ?string $telephone = null): array
{
    return test()->postJson('/api/file/tickets', array_filter([
        'service_uuid' => serviceUuid($prefixe), 'priorite' => $priorite, 'telephone' => $telephone,
    ]))->assertStatus(201)->json('ticket');
}

function appeler(string $prefixe = 'A'): ?array
{
    return test()->postJson('/api/file/appeler', ['services' => [serviceUuid($prefixe)]])->assertOk()->json('ticket');
}

it('crée les services de départ selon le profil, avec une salle chacun', function () {
    equipe();

    $services = $this->getJson('/api/file/services')->json('data');
    expect(collect($services)->pluck('prefixe')->all())->toBe(['A', 'M', 'L', 'C', 'P'])
        ->and($services[0]['points'][0]['nom'])->toBe('Salle 1');
});

it('numérote les tickets par service, et repart de 1 chaque jour', function () {
    equipe();

    expect(ticket('A')['code'])->toBe('A001')
        ->and(ticket('A')['code'])->toBe('A002')
        ->and(ticket('C')['code'])->toBe('C001');

    Carbon::setTestNow(now()->addDay());
    expect(ticket('A')['code'])->toBe('A001');
    Carbon::setTestNow();
});

it("appelle l'urgence d'abord, puis les prioritaires, sans oublier les ordinaires", function () {
    equipe();
    Service::query()->update(['ratio_prioritaires' => 2]);

    $o1 = ticket('A', 'ordinaire');
    $p1 = ticket('A', 'prioritaire');
    $p2 = ticket('A', 'prioritaire');
    $p3 = ticket('A', 'prioritaire');
    $u = ticket('A', 'urgence');

    expect(appeler()['code'])->toBe($u['code'])     // l'urgence passe toujours
        ->and(appeler()['code'])->toBe($p1['code'])
        ->and(appeler()['code'])->toBe($o1['code'])  // 2 prioritaires de suite : un ordinaire
        ->and(appeler()['code'])->toBe($p2['code'])
        ->and(appeler()['code'])->toBe($p3['code'])
        ->and(appeler())->toBeNull();
});

it("n'appelle jamais deux fois le même ticket", function () {
    equipe();
    ticket('A');
    ticket('A');

    $premier = appeler();
    $second = appeler();

    expect($premier['uuid'])->not->toBe($second['uuid'])
        ->and(Ticket::where('statut', 'appele')->count())->toBe(2);
});

it('donne la position et une attente estimée', function () {
    equipe();
    Service::where('prefixe', 'A')->update(['duree_moyenne_min' => 10]);
    ticket('A');
    ticket('A');
    $troisieme = ticket('A');

    expect($troisieme['position'])->toBe(2)->and($troisieme['attente_min'])->toBe(30);
});

it('transfère vers un autre service sans perdre la priorité', function () {
    equipe();
    $t = ticket('A', 'prioritaire');
    appeler();

    $nouveau = $this->postJson("/api/file/tickets/{$t['uuid']}/transferer", ['service_uuid' => serviceUuid('C')])
        ->assertOk()->json('ticket');

    expect($nouveau['code'])->toBe('C001')
        ->and($nouveau['priorite'])->toBe('prioritaire')
        ->and(Ticket::where('uuid', $t['uuid'])->first()->statut->value)->toBe('transfere')
        ->and(Ticket::where('uuid', $nouveau['uuid'])->value('ticket_precedent_id'))->toBe(Ticket::where('uuid', $t['uuid'])->value('id'));
});

it('prévient par SMS le patient appelé et ceux dont le tour approche', function () {
    equipe();
    $appele = ticket('A', 'ordinaire', '+22370000010');
    foreach (range(11, 15) as $n) {
        ticket('A', 'ordinaire', '+223700000'.$n);
    }

    appeler();
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);

    $destinataires = collect($sms->envoyes)->pluck('telephone');
    expect($sms->envoyes[0]['message'])->toContain("c'est à vous")->toContain($appele['code'])
        ->and($destinataires)->toContain('+22370000011', '+22370000012', '+22370000013')
        ->not->toContain('+22370000014');

    // Pas deux fois le même avertissement.
    appeler();
    expect(collect($sms->envoyes)->where('telephone', '+22370000012')->count())->toBe(1);
});

it('suit les états du ticket jusqu\'à la fin du passage', function () {
    equipe();
    $t = ticket('A');
    appeler();

    foreach (['servir' => 'en_service', 'terminer' => 'termine'] as $action => $statut) {
        $this->postJson("/api/file/tickets/{$t['uuid']}/{$action}")->assertOk()->assertJsonPath('ticket.statut', $statut);
    }
});

it('respecte les droits : la caisse donne des tickets, le médecin appelle sans régler', function () {
    $admin = equipe();
    $caissier = User::factory()->create(['etablissement_id' => $admin->etablissement_id, 'role' => 'caissier']);
    $medecin = User::factory()->create(['etablissement_id' => $admin->etablissement_id, 'role' => 'medecin']);

    $this->actingAs($caissier, 'api')->postJson('/api/file/tickets', ['service_uuid' => serviceUuid('A')])->assertStatus(201);
    $this->actingAs($medecin, 'api')->postJson('/api/file/appeler', ['services' => [serviceUuid('A')]])->assertOk();
    $this->actingAs($medecin, 'api')->postJson('/api/file/services', ['nom' => 'X', 'prefixe' => 'X'])->assertForbidden();
});

it("n'ouvre pas la file à un établissement qui n'a pas le module", function () {
    $etab = Etablissement::factory()->create(['profil' => 'depot_medicaments', 'type' => null, 'modules' => null]);
    $this->actingAs(User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']), 'api')
        ->getJson('/api/file/services')->assertForbidden();
});

it('ne mélange pas les établissements', function () {
    equipe();
    $autre = Service::where('prefixe', 'A')->value('uuid');
    equipe(); // un second établissement, connecté

    $this->postJson('/api/file/tickets', ['service_uuid' => $autre])->assertNotFound();
});

// ------------------------------------------------------------ côté patient

it('laisse une personne prendre un ticket depuis son téléphone et le suivre', function () {
    $admin = equipe();
    $etab = Etablissement::find($admin->etablissement_id);
    $token = test()->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => '70000099', 'nom' => 'Awa', 'pin' => '1234',
    ])->json('access_token');
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    $file = $this->getJson("/api/public/etablissements/{$etab->uuid}/file")->assertOk()->json('services');
    $t = $this->withToken($token)->postJson('/api/compte/tickets', ['service_uuid' => $file[0]['uuid']])
        ->assertStatus(201)->json('ticket');
    // Le même ticket, pas un deuxième.
    $this->withToken($token)->postJson('/api/compte/tickets', ['service_uuid' => $file[0]['uuid']])
        ->assertOk()->assertJsonPath('ticket.uuid', $t['uuid']);

    expect($this->withToken($token)->getJson('/api/compte/tickets')->json('data.0.code'))->toBe('A001')
        ->and(Ticket::first()->personne_id)->toBe(Personne::where('telephone', '+22370000099')->value('id'));

    // Suivi public par QR : sans le nom.
    $public = $this->getJson("/api/public/tickets/{$t['uuid']}")->assertOk()->json('ticket');
    expect($public)->not->toHaveKey('nom')->and($public['position'])->toBe(0);
});

// ------------------------------------------------------------ appareils

it('appaire un écran de salle et une borne, puis les retire', function () {
    equipe();

    $code = $this->postJson('/api/file/appareils', ['mode' => 'borne', 'nom' => 'Borne accueil'])
        ->assertStatus(201)->json('code');
    auth()->forgetGuards();

    $jeton = $this->postJson('/api/appareils/appairer', ['code' => $code])->assertOk()->json('jeton');
    $this->postJson('/api/appareils/appairer', ['code' => $code])->assertStatus(422); // usage unique

    $entetes = ['X-Appareil' => $jeton];
    $service = $this->getJson('/api/appareil/services', $entetes)->assertOk()->json('data.0.uuid');
    $this->postJson('/api/appareil/tickets', ['service_uuid' => $service], $entetes)
        ->assertStatus(201)->assertJsonPath('ticket.code', 'A001');
    $this->getJson('/api/appareil/etat', $entetes)->assertOk()->assertJsonPath('services.0.en_attente', 1);

    $uuid = Appareil::first()->uuid;
    $this->actingAs(User::first(), 'api')->deleteJson("/api/file/appareils/{$uuid}")->assertOk();
    auth()->forgetGuards();
    $this->getJson('/api/appareil/etat', $entetes)->assertUnauthorized();
});

it("refuse qu'un écran donne des tickets", function () {
    equipe();
    $code = $this->postJson('/api/file/appareils', ['mode' => 'ecran', 'nom' => 'Télé salle'])->json('code');
    $jeton = $this->postJson('/api/appareils/appairer', ['code' => $code])->json('jeton');

    $this->postJson('/api/appareil/tickets', ['service_uuid' => serviceUuid('A')], ['X-Appareil' => $jeton])
        ->assertForbidden();
});

it('affiche la page de suivi du QR, sans le nom du patient', function () {
    equipe();
    $t = $this->postJson('/api/file/tickets', [
        'service_uuid' => serviceUuid('A'), 'nom' => 'Awa Traoré',
    ])->json('ticket');

    expect($t['suivi_url'])->toEndWith('/t/'.$t['uuid']);
    $this->get('/t/'.$t['uuid'])->assertOk()->assertSee('A001')->assertSee('Vous êtes le prochain')->assertDontSee('Awa');
});

it("met le numéro saisi à l'accueil au format international", function () {
    equipe();
    $t = $this->postJson('/api/file/tickets', [
        'service_uuid' => serviceUuid('A'), 'telephone' => '76 12 34 56',
    ])->json('ticket');

    expect($t['telephone'])->toBe('+22376123456');
});

it("ne laisse pas un écran qui interroge souvent bloquer l'appairage ni la connexion", function () {
    equipe();
    $code = $this->postJson('/api/file/appareils', ['mode' => 'ecran', 'nom' => 'Télé'])->json('code');
    $jeton = $this->postJson('/api/appareils/appairer', ['code' => $code])->json('jeton');

    // Une heure d'écran en une minute : bien plus que les anciennes limites partagées.
    foreach (range(1, 70) as $_) {
        $this->getJson('/api/appareil/etat', ['X-Appareil' => $jeton]);
    }

    $autre = $this->actingAs(User::first(), 'api')
        ->postJson('/api/file/appareils', ['mode' => 'borne', 'nom' => 'Borne'])->json('code');
    auth()->forgetGuards();
    $this->postJson('/api/appareils/appairer', ['code' => $autre])->assertOk();
    $this->getJson('/api/pays')->assertOk();
});

it('donne les chiffres du jour : passages, absents, attente moyenne, affluence', function () {
    equipe();
    Carbon::setTestNow(now()->setTime(9, 0));
    $a = ticket('A');
    $b = ticket('A');
    Carbon::setTestNow(now()->addMinutes(20));
    appeler();
    $this->postJson("/api/file/tickets/{$a['uuid']}/terminer");
    Carbon::setTestNow(now()->addMinutes(10));
    appeler();
    $this->postJson("/api/file/tickets/{$b['uuid']}/absent");

    $stats = $this->getJson('/api/file/statistiques')->assertOk()->json();
    Carbon::setTestNow();

    expect($stats['total'])->toBe(2)
        ->and($stats['services'][0]['servis'])->toBe(1)
        ->and($stats['services'][0]['absents'])->toBe(1)
        ->and($stats['services'][0]['attente_moyenne_min'])->toEqual(25)
        ->and($stats['services'][0]['attente_max_min'])->toEqual(30)
        ->and($stats['par_heure'][0]['tickets'])->toBe(2);
});
