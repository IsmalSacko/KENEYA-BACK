<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recherche les établissements par proximité (tri distance)', function () {
    $proche = Etablissement::factory()->create([
        'actif' => true, 'nom' => 'Cabinet Proche', 'latitude' => 12.61, 'longitude' => -8.00,
    ]);
    $loin = Etablissement::factory()->create([
        'actif' => true, 'nom' => 'Cabinet Loin', 'latitude' => 14.00, 'longitude' => -6.00,
    ]);
    // Sans coordonnées → exclu de la recherche géo.
    Etablissement::factory()->create(['actif' => true, 'latitude' => null, 'longitude' => null]);

    $resp = $this->getJson('/api/public/etablissements?lat=12.6&lng=-8.0')->assertOk();

    $data = $resp->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0]['uuid'])->toBe($proche->uuid);
    expect($data[1]['uuid'])->toBe($loin->uuid);
    expect($data[0]['distance_km'])->toBeLessThan($data[1]['distance_km']);
    expect($data[0]['itineraire_url'])->toContain('destination=');
});

it('filtre la recherche par type et par nom', function () {
    Etablissement::factory()->create(['actif' => true, 'type' => 'pharmacie', 'nom' => 'Pharmacie du Fleuve']);
    Etablissement::factory()->create(['actif' => true, 'type' => 'cabinet', 'nom' => 'Cabinet Central']);

    expect($this->getJson('/api/public/etablissements?type=pharmacie')->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/public/etablissements?q=Central')->json('data'))->toHaveCount(1);
});

it('permet à un patient de prendre un rendez-vous (public, sans auth)', function () {
    $etab = Etablissement::factory()->create(['actif' => true]);

    $resp = $this->postJson('/api/public/rendez-vous', [
        'etablissement_uuid' => $etab->uuid,
        'patient_nom' => 'Awa Traoré',
        'patient_telephone' => '76001122',
        'date_souhaitee' => now()->addDays(2)->toIso8601String(),
        'motif' => 'Consultation générale',
    ])->assertStatus(201)->assertJsonPath('rendez_vous.statut', 'demande');

    $this->assertDatabaseHas('rendez_vous', [
        'etablissement_id' => $etab->id,
        'patient_nom' => 'Awa Traoré',
        'statut' => 'demande',
    ]);
});

it('est idempotent avec un uuid client', function () {
    $etab = Etablissement::factory()->create(['actif' => true]);
    $uuid = '22222222-2222-4222-8222-222222222222';
    $payload = [
        'uuid' => $uuid,
        'etablissement_uuid' => $etab->uuid,
        'patient_nom' => 'Sékou',
        'patient_telephone' => '70001122',
        'date_souhaitee' => now()->addDay()->toIso8601String(),
    ];

    $this->postJson('/api/public/rendez-vous', $payload)->assertStatus(201);
    $this->postJson('/api/public/rendez-vous', $payload)->assertStatus(201);

    expect(RendezVous::where('uuid', $uuid)->count())->toBe(1);
});

it('rejette un rendez-vous vers un établissement inactif', function () {
    $etab = Etablissement::factory()->create(['actif' => false]);

    $this->postJson('/api/public/rendez-vous', [
        'etablissement_uuid' => $etab->uuid,
        'patient_nom' => 'X',
        'patient_telephone' => '70000000',
        'date_souhaitee' => now()->addDay()->toIso8601String(),
    ])->assertStatus(404);
});

it('liste et confirme les rendez-vous côté personnel, scopé à l\'établissement', function () {
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $autre = Etablissement::factory()->create();
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin']);

    $rdv = RendezVous::factory()->create(['etablissement_id' => $etab->id, 'statut' => 'demande']);
    RendezVous::factory()->create(['etablissement_id' => $autre->id]); // hors périmètre

    $this->actingAs($admin, 'api');

    expect($this->getJson('/api/rendez-vous')->assertOk()->json())->toHaveCount(1);

    $this->patchJson("/api/rendez-vous/{$rdv->id}", ['statut' => 'confirme'])
        ->assertOk()
        ->assertJsonPath('rendez_vous.statut', 'confirme');
});

it('empêche de traiter un rendez-vous d\'un autre établissement', function () {
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $autre = Etablissement::factory()->create();
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin']);
    $rdvAutre = RendezVous::factory()->create(['etablissement_id' => $autre->id]);

    $this->actingAs($admin, 'api');

    $this->patchJson("/api/rendez-vous/{$rdvAutre->id}", ['statut' => 'confirme'])
        ->assertStatus(403);
});

it('réserve l’ancienne API des rendez-vous et des consultations aux bons rôles', function () {
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $rdv = RendezVous::factory()->create(['etablissement_id' => $etab->id, 'statut' => 'demande']);
    $pharmacien = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'pharmacien']);
    $laborantin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'laborantin']);

    $this->actingAs($pharmacien, 'api')->getJson('/api/rendez-vous')->assertForbidden();
    $this->actingAs($pharmacien, 'api')->getJson('/api/consultations')->assertForbidden();
    // Le laborantin voit les rendez-vous, sans les traiter.
    $this->actingAs($laborantin, 'api')->getJson('/api/rendez-vous')->assertOk();
    $this->actingAs($laborantin, 'api')->patchJson("/api/rendez-vous/{$rdv->id}", ['statut' => 'confirme'])->assertForbidden();
});
