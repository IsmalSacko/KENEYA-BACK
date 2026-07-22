<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('permet à un admin de créer un établissement et le lui rattache', function () {
    $etab = Etablissement::factory()->create();
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin']);
    $this->actingAs($admin, 'api');

    $resp = $this->postJson('/api/etablissements', [
        'nom' => 'Nouvelle Pharmacie',
        'type' => 'pharmacie',
        'latitude' => 12.6392,
        'longitude' => -8.0029,
    ])->assertStatus(201);

    $nouvelId = $resp->json('etablissement.id');
    expect($nouvelId)->not->toBeNull();
    $this->assertDatabaseHas('etablissements', ['nom' => 'Nouvelle Pharmacie', 'type' => 'pharmacie']);
    // rattaché à l'admin
    expect($admin->etablissements()->where('etablissements.id', $nouvelId)->exists())->toBeTrue();
    // itineraire_url exposé
    expect($resp->json('etablissement.itineraire_url'))->toContain('destination=');
});

it('interdit la création à un non-admin', function () {
    $etab = Etablissement::factory()->create();
    $caissier = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'caissier']);
    $this->actingAs($caissier, 'api');

    $this->postJson('/api/etablissements', [
        'nom' => 'Interdit',
        'type' => 'cabinet',
    ])->assertStatus(403);

    $this->assertDatabaseMissing('etablissements', ['nom' => 'Interdit']);
});

it('est idempotent quand un uuid client est fourni (offline rejoué)', function () {
    $etab = Etablissement::factory()->create();
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin']);
    $this->actingAs($admin, 'api');

    $uuid = '11111111-1111-4111-8111-111111111111';
    $payload = ['uuid' => $uuid, 'nom' => 'Cabinet Central', 'type' => 'cabinet'];

    $this->postJson('/api/etablissements', $payload)->assertStatus(201);
    $this->postJson('/api/etablissements', $payload)->assertStatus(201);

    expect(Etablissement::where('uuid', $uuid)->count())->toBe(1);
});
