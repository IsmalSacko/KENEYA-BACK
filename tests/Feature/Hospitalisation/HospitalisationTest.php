<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\User;
use App\Models\Visite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function membreHopital(Etablissement $etab, string $role): User
{
    $m = User::where(['etablissement_id' => $etab->id, 'role' => $role])->first()
        ?? User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role, 'name' => ucfirst($role)]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($m, 'api');

    return $m;
}

/** Une clinique, une salle de 4 lits à 5 000 la nuit, un patient à l'accueil. */
function clinique(): array
{
    $etab = Etablissement::factory()->create(['nom' => 'Clinique Kénédougou', 'profil' => 'clinique', 'type' => null, 'modules' => null]);
    membreHopital($etab, 'admin_etablissement');
    test()->getJson('/api/file/services')->assertOk();
    test()->postJson('/api/hospitalisation/chambres', ['nom' => 'Salle Médecine', 'service' => 'médecine', 'lits' => 4, 'prix' => 5000])->assertStatus(201);
    $lits = collect(test()->getJson('/api/hospitalisation/chambres')->json('data.0.lits'));
    membreHopital($etab, 'accueil');
    $visite = test()->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'), 'nom' => 'Bakary Sanogo',
    ])->assertStatus(201)->json('ticket.visite_uuid');
    membreHopital($etab, 'medecin');

    return [$etab, $visite, $lits];
}

function admettre(string $visite, string $lit): array
{
    return test()->postJson("/api/visites/{$visite}/hospitalisation", ['lit_uuid' => $lit, 'motif' => 'Paludisme grave'])
        ->assertStatus(201)->json('sejour');
}

it('admet sur un lit libre, jamais sur un lit occupé', function () {
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);

    expect($s['lit'])->toBe('Salle Médecine · lit 1')
        ->and($s['bracelet'])->toBe("ngoni-sante:bracelet:{$s['uuid']}")
        ->and($this->getJson('/api/hospitalisation/chambres')->json('data.0.lits.0.patient'))->toBe('Bakary Sanogo');

    membreHopital($etab, 'accueil');
    $autre = $this->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'), 'nom' => 'Autre',
    ])->json('ticket.visite_uuid');
    membreHopital($etab, 'medecin');
    $this->postJson("/api/visites/{$autre}/hospitalisation", ['lit_uuid' => $lits[0]['uuid'], 'motif' => 'X'])
        ->assertStatus(422)->assertJsonPath('message', 'Ce lit est occupé.');
    $this->postJson("/api/visites/{$visite}/hospitalisation", ['lit_uuid' => $lits[1]['uuid'], 'motif' => 'X'])
        ->assertStatus(422)->assertJsonPath('message', 'Ce patient est déjà hospitalisé.');
});

it('déroule le plan de soins du jour, et refuse le soin sans le bon bracelet', function () {
    Carbon::setTestNow('2026-10-27 07:00'); // Bamako = UTC
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    $plan = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", [
        'produit' => 'Artésunate', 'dose' => '120 mg', 'voie' => 'iv', 'horaires' => ['20:00', '08:00'],
    ])->assertStatus(201)->json('sejour.plan');

    expect(collect($plan)->pluck('heure')->all())->toBe(['08:00', '20:00'])
        ->and($plan[0]['statut'])->toBe('a_faire');

    membreHopital($etab, 'infirmier');
    $prise = ['soin_uuid' => $plan[0]['soin_uuid'], 'prevue_at' => $plan[0]['prevue_at'], 'statut' => 'faite'];
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [...$prise, 'bracelet' => 'ngoni-sante:bracelet:autre-patient'])
        ->assertStatus(422)->assertJsonPath('message', 'Ce bracelet n’est pas celui du patient : ne donnez pas ce soin.');
    $apres = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [...$prise, 'bracelet' => $s['bracelet']])
        ->assertOk()->json('sejour.plan');
    expect($apres[0]['statut'])->toBe('faite')->and($apres[0]['par'])->toBe('Infirmier');
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [...$prise, 'bracelet' => $s['bracelet']])->assertStatus(422);

    // Une heure qui n'est pas au plan.
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [...$prise, 'prevue_at' => '2026-10-27T13:00:00Z', 'bracelet' => $s['bracelet']])
        ->assertStatus(422);
    // Refusée par le patient : pas besoin du bracelet, mais c'est noté.
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [
        'soin_uuid' => $plan[1]['soin_uuid'], 'prevue_at' => $plan[1]['prevue_at'], 'statut' => 'refusee', 'commentaire' => 'Vomit',
    ])->assertOk()->assertJsonPath('sejour.plan.1.statut', 'refusee');
});

it('signale les prises en retard', function () {
    Carbon::setTestNow('2026-10-27 07:00');
    [, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", ['produit' => 'Paracétamol', 'voie' => 'po', 'horaires' => ['08:00']]);

    Carbon::setTestNow('2026-10-27 09:30');
    expect($this->getJson('/api/hospitalisation/sejours')->json('data.0.en_retard'))->toBe(1);
});

it('fait sortir le patient et facture les nuits sur la visite', function () {
    Carbon::setTestNow('2026-10-25 22:00');
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", ['produit' => 'Paracétamol', 'voie' => 'po', 'horaires' => ['08:00']]);
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/transferer", ['lit_uuid' => $lits[2]['uuid']])
        ->assertOk()->assertJsonPath('sejour.lit', 'Salle Médecine · lit 3');

    Carbon::setTestNow('2026-10-27 10:00');
    $fin = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/sortie", ['mode_sortie' => 'gueri', 'resume' => 'Évolution favorable'])
        ->assertOk()->json('sejour');

    expect($fin['statut'])->toBe('sortie')->and($fin['nuits'])->toBe(2)
        ->and($fin['soins'][0]['arrete'])->toBeTrue()
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(10000)
        ->and($this->getJson('/api/hospitalisation/chambres')->json('data.0.lits.2.patient'))->toBeNull();
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", ['produit' => 'X', 'voie' => 'po', 'horaires' => ['08:00']])->assertStatus(422);
});

it('ouvre le séjour en scannant le bracelet', function () {
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    membreHopital($etab, 'infirmier');

    $this->postJson('/api/hospitalisation/sejours/lire', ['qr' => $s['bracelet']])->assertOk()->assertJsonPath('sejour.patient', 'Bakary Sanogo');
    $this->postJson('/api/hospitalisation/sejours/lire', ['qr' => 'ngoni-sante:labo:x1234'])->assertStatus(422);
});

it('réserve les gestes à ceux qui en ont le droit', function () {
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);

    membreHopital($etab, 'infirmier'); // administre, ne prescrit pas
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", ['produit' => 'X', 'voie' => 'po', 'horaires' => ['08:00']])->assertForbidden();
    membreHopital($etab, 'caissier');
    $this->getJson("/api/hospitalisation/sejours/{$s['uuid']}")->assertForbidden();

    $autre = Etablissement::factory()->create(['profil' => 'clinique', 'type' => null, 'modules' => null]);
    membreHopital($autre, 'medecin');
    $this->getJson("/api/hospitalisation/sejours/{$s['uuid']}")->assertNotFound();
    $this->postJson("/api/visites/{$visite}/hospitalisation", ['lit_uuid' => $lits[1]['uuid'], 'motif' => 'X'])->assertNotFound();
});
