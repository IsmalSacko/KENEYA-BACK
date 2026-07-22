<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renvoie des stats utilisateurs cohérentes pour un admin, scopées à son établissement', function () {
    $etab = Etablissement::factory()->create();
    $autre = Etablissement::factory()->create();

    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin', 'actif' => true]);
    User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'medecin', 'actif' => true]);
    User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'caissier', 'actif' => false]);
    // Utilisateur d'un autre établissement : ne doit pas compter.
    User::factory()->create(['etablissement_id' => $autre->id, 'role' => 'admin']);

    $this->actingAs($admin, 'api');

    $resp = $this->getJson('/api/stats/users')->assertOk();

    expect($resp->json('total'))->toBe(3);
    expect($resp->json('actifs'))->toBe(2);
    expect($resp->json('inactifs'))->toBe(1);
    expect($resp->json('par_role.admin'))->toBe(1);
    expect($resp->json('par_role.medecin'))->toBe(1);
    expect($resp->json('inscriptions_par_mois'))->toHaveCount(12);
});

it('refuse l\'accès aux non-admins', function () {
    $etab = Etablissement::factory()->create();
    $medecin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'medecin']);
    $this->actingAs($medecin, 'api');

    $this->getJson('/api/stats/users')->assertStatus(403);
});
