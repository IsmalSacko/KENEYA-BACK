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

it('modifie une chambre ; fermée, ses lits ne reçoivent plus personne', function () {
    [$etab, $visite, $lits] = clinique();
    membreHopital($etab, 'admin_etablissement');
    $chambre = $this->getJson('/api/hospitalisation/chambres')->json('data.0.uuid');

    $this->patchJson("/api/hospitalisation/chambres/{$chambre}", ['nom' => 'Salle A', 'prix' => 6000, 'ajouter_lits' => 2])->assertOk();
    $c = $this->getJson('/api/hospitalisation/chambres')->json('data.0');
    expect($c['nom'])->toBe('Salle A')->and($c['prix'])->toBe(6000)->and($c['lits'])->toHaveCount(6)
        ->and(collect($c['lits'])->pluck('numero')->last())->toBe('6');

    $this->patchJson("/api/hospitalisation/chambres/{$chambre}", ['actif' => false])->assertOk();
    expect($this->getJson('/api/hospitalisation/chambres')->json('data.0.lits.0.actif'))->toBeFalse();
    membreHopital($etab, 'medecin');
    $this->postJson("/api/visites/{$visite}/hospitalisation", ['lit_uuid' => $lits[0]['uuid'], 'motif' => 'X'])
        ->assertStatus(422)->assertJsonPath('message', 'Ce lit est fermé.');

    // On ne ferme pas une chambre où un patient est couché.
    membreHopital($etab, 'admin_etablissement');
    $this->patchJson("/api/hospitalisation/chambres/{$chambre}", ['actif' => true])->assertOk();
    membreHopital($etab, 'medecin');
    admettre($visite, $lits[0]['uuid']);
    membreHopital($etab, 'admin_etablissement');
    $this->patchJson("/api/hospitalisation/chambres/{$chambre}", ['actif' => false])->assertStatus(422);
});

it('liste les patients sortis', function () {
    [, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/sortie", ['mode_sortie' => 'gueri'])->assertOk();

    expect($this->getJson('/api/hospitalisation/sejours')->json('data'))->toBe([])
        ->and($this->getJson('/api/hospitalisation/sejours?statut=sortie')->json('data.0.uuid'))->toBe($s['uuid'])
        ->and($this->getJson('/api/hospitalisation/sejours?statut=sortie&q=bakary')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/hospitalisation/sejours?statut=sortie&q=zzz')->json('data'))->toBe([]);
});

it('facture les nuits sur une nouvelle facture quand la visite est clôturée', function () {
    Carbon::setTestNow('2026-10-25 22:00');
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    Visite::where('uuid', $visite)->update(['statut' => 'cloturee', 'cloturee_at' => now()]);

    Carbon::setTestNow('2026-10-27 10:00');
    $r = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/sortie", ['mode_sortie' => 'ameliore'])->assertOk()->json();

    expect($r['facture']['nouvelle'])->toBeTrue()->and($r['facture']['nuits'])->toBe(2)->and($r['facture']['montant'])->toBe(10000)
        ->and($r['facture']['visite_uuid'])->not->toBe($visite)
        ->and(Visite::where('uuid', $r['facture']['visite_uuid'])->value('total'))->toBe(10000)
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(0);
});

it('annule une admission faite par erreur, le jour même et sans soin donné', function () {
    Carbon::setTestNow('2026-10-27 07:00');
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    expect($s['annulable'])->toBeTrue();

    $fin = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/annuler")->assertOk()->json('sejour');
    expect($fin['statut'])->toBe('annulee')
        ->and($this->getJson('/api/hospitalisation/chambres')->json('data.0.lits.0.patient'))->toBeNull()
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(0);
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/sortie", ['mode_sortie' => 'gueri'])
        ->assertStatus(422)->assertJsonPath('message', 'Cette admission est annulée.');

    // Un soin donné : on fait la sortie.
    $s = admettre($visite, $lits[1]['uuid']);
    $plan = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", ['produit' => 'Paracétamol', 'voie' => 'po', 'horaires' => ['08:00']])->json('sejour.plan');
    membreHopital($etab, 'infirmier');
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [
        'soin_uuid' => $plan[0]['soin_uuid'], 'prevue_at' => $plan[0]['prevue_at'], 'statut' => 'faite', 'bracelet' => $s['bracelet'],
    ])->assertOk();
    membreHopital($etab, 'medecin');
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/annuler")->assertStatus(422);

    // Le lendemain non plus.
    Carbon::setTestNow('2026-10-28 09:00');
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/annuler")
        ->assertStatus(422)->assertJsonPath('message', 'L’admission date d’un autre jour : faites la sortie du patient.');
});

it('corrige une prise notée par erreur, dans les heures qui suivent', function () {
    Carbon::setTestNow('2026-10-27 07:00');
    [$etab, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    $plan = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", ['produit' => 'Artésunate', 'voie' => 'iv', 'horaires' => ['08:00']])->json('sejour.plan');
    membreHopital($etab, 'infirmier');
    $prise = ['soin_uuid' => $plan[0]['soin_uuid'], 'prevue_at' => $plan[0]['prevue_at']];
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations/corriger", [...$prise, 'statut' => 'faite', 'motif' => 'X', 'bracelet' => $s['bracelet']])
        ->assertStatus(422)->assertJsonPath('message', 'Cette prise n’est pas encore notée.');

    Carbon::setTestNow('2026-10-27 08:05');
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations", [...$prise, 'statut' => 'omise'])->assertOk();
    // « Non fait » par erreur : il l'a bien reçu. Le bracelet est exigé.
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations/corriger", [...$prise, 'statut' => 'faite', 'motif' => 'Donné par la collègue'])
        ->assertStatus(422);
    $p = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations/corriger", [
        ...$prise, 'statut' => 'faite', 'motif' => 'Donné par la collègue', 'bracelet' => $s['bracelet'],
    ])->assertOk()->json('sejour.plan.0');
    expect($p['statut'])->toBe('faite')->and($p['corrigee'])->toBeTrue()->and($p['commentaire'])->toBe('Donné par la collègue');

    Carbon::setTestNow('2026-10-27 15:00');
    expect($this->getJson("/api/hospitalisation/sejours/{$s['uuid']}")->json('sejour.plan.0.corrigeable'))->toBeFalse();
    $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/administrations/corriger", [...$prise, 'statut' => 'refusee', 'motif' => 'X'])
        ->assertStatus(422);
});

it('dit si le patient de la visite est déjà hospitalisé', function () {
    [$etab, $visite, $lits] = clinique();
    expect($this->getJson("/api/visites/{$visite}/hospitalisation")->assertOk()->json('sejour'))->toBeNull();
    $s = admettre($visite, $lits[0]['uuid']);
    membreHopital($etab, 'infirmier');
    expect($this->getJson("/api/visites/{$visite}/hospitalisation")->json('sejour.uuid'))->toBe($s['uuid']);
});

it('garde la fin d’une prescription', function () {
    Carbon::setTestNow('2026-10-27 07:00');
    [, $visite, $lits] = clinique();
    $s = admettre($visite, $lits[0]['uuid']);
    $soin = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/soins", [
        'produit' => 'Amoxicilline', 'voie' => 'po', 'horaires' => ['08:00'], 'fin' => '2026-10-29',
    ])->assertStatus(201)->json('sejour.soins.0');
    expect($soin['fin'])->toBe('2026-10-29')->and($soin['debut'])->toBe('2026-10-27');

    Carbon::setTestNow('2026-10-30 07:00');
    expect($this->getJson("/api/hospitalisation/sejours/{$s['uuid']}")->json('sejour.plan'))->toBe([]);
});

it('admet depuis un lit libre un patient connu, sans visite', function () {
    Carbon::setTestNow('2026-10-25 22:00');
    [$etab, $visite, $lits] = clinique();
    $premier = admettre($visite, $lits[0]['uuid']);
    $this->postJson("/api/hospitalisation/sejours/{$premier['uuid']}/annuler")->assertOk();

    $s = $this->postJson('/api/hospitalisation/sejours', ['dossier_uuid' => $premier['dossier_uuid'], 'lit_uuid' => $lits[1]['uuid'], 'motif' => 'Surveillance'])
        ->assertStatus(201)->json('sejour');
    expect($s['patient'])->toBe('Bakary Sanogo')->and($s['lit'])->toBe('Salle Médecine · lit 2');

    // Les nuits vont sur une facture du jour.
    Carbon::setTestNow('2026-10-26 10:00');
    $f = $this->postJson("/api/hospitalisation/sejours/{$s['uuid']}/sortie", ['mode_sortie' => 'gueri'])->json('facture');
    expect($f['nouvelle'])->toBeTrue()->and($f['montant'])->toBe(5000);

    $autre = Etablissement::factory()->create(['profil' => 'clinique', 'type' => null, 'modules' => null]);
    membreHopital($autre, 'medecin');
    $this->postJson('/api/hospitalisation/sejours', ['dossier_uuid' => $premier['dossier_uuid'], 'lit_uuid' => $lits[2]['uuid'], 'motif' => 'X'])
        ->assertNotFound();
});
