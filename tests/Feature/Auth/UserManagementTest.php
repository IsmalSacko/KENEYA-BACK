<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Crée un établissement + un utilisateur du rôle donné, authentifié. */
function actingRole(string $role): array
{
    $etab = Etablissement::factory()->create();
    $user = User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role]);
    test()->actingAs($user, 'api');

    return [$etab, $user];
}

// ----------------------------------------------------------------- register
it("crée un admin d'établissement (pas un admin d'instance) à l'inscription", function () {
    $resp = $this->postJson('/api/register', [
        'nom_etablissement' => 'Cabinet Test',
        'type' => 'cabinet',
        'name' => 'Fatou',
        'telephone' => '76000123',
        'pin' => '1234',
    ])->assertStatus(201);

    expect($resp->json('user.role'))->toBe('admin_etablissement');
    $this->assertDatabaseHas('users', ['telephone' => '76000123', 'role' => 'admin_etablissement']);
});

// ----------------------------------------------------------------- addUser
it("permet à un admin d'établissement d'ajouter un membre de son établissement", function () {
    [$etab] = actingRole('admin_etablissement');

    $this->postJson('/api/users', [
        'name' => 'Awa', 'telephone' => '76001111', 'role' => 'infirmier', 'pin' => '1234',
    ])->assertStatus(201);

    // Le numéro est rangé au format international, comme le compte Ngoni Santé.
    $this->assertDatabaseHas('users', [
        'telephone' => '+22376001111', 'role' => 'infirmier', 'etablissement_id' => $etab->id,
    ]);
});

it("interdit à un admin d'établissement de créer un admin d'instance", function () {
    actingRole('admin_etablissement');

    $this->postJson('/api/users', [
        'name' => 'Pirate', 'telephone' => '76002222', 'role' => 'admin', 'pin' => '1234',
    ])->assertStatus(422); // rôle non autorisé (Rule::in)

    $this->assertDatabaseMissing('users', ['telephone' => '76002222']);
});

it('interdit aux rôles opérationnels d\'ajouter un utilisateur', function (string $role) {
    actingRole($role);

    $this->postJson('/api/users', [
        'name' => 'X', 'telephone' => '76003333', 'role' => 'medecin', 'pin' => '1234',
    ])->assertStatus(403);

    $this->assertDatabaseMissing('users', ['telephone' => '76003333']);
})->with(['medecin', 'pharmacien', 'infirmier', 'caissier', 'laborantin', 'gestionnaire', 'sage_femme']);

it("permet à l'admin d'instance d'attribuer n'importe quel rôle", function () {
    actingRole('admin');

    $this->postJson('/api/users', [
        'name' => 'Co-admin', 'telephone' => '76004444', 'role' => 'admin', 'pin' => '1234',
    ])->assertStatus(201);
});

// ----------------------------------------------------------------- update / delete
it('interdit à un médecin de modifier ou supprimer un utilisateur', function () {
    [$etab] = actingRole('medecin');
    $cible = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'caissier']);

    $this->patchJson("/api/users/{$cible->id}", ['name' => 'Hack'])->assertStatus(403);
    $this->deleteJson("/api/users/{$cible->id}")->assertStatus(403);
});

it("permet à un admin d'établissement de modifier un membre de son établissement", function () {
    [$etab] = actingRole('admin_etablissement');
    $cible = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'caissier']);

    $this->patchJson("/api/users/{$cible->id}", ['name' => 'Corrigé', 'role' => 'pharmacien'])
        ->assertOk();

    $this->assertDatabaseHas('users', ['id' => $cible->id, 'name' => 'Corrigé', 'role' => 'pharmacien']);
});

it('empêche de changer son propre rôle et de se supprimer soi-même', function () {
    [, $admin] = actingRole('admin_etablissement');

    $this->patchJson("/api/users/{$admin->id}", ['role' => 'admin'])->assertStatus(403);
    $this->deleteJson("/api/users/{$admin->id}")->assertStatus(403);
});

// ----------------------------------------------------------------- établissements (instance-only)
it("interdit à un admin d'établissement de créer un nouvel établissement", function () {
    actingRole('admin_etablissement');

    $this->postJson('/api/etablissements', ['nom' => 'Autre', 'type' => 'cabinet'])
        ->assertStatus(403);
});
