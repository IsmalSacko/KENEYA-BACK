<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Un membre du rôle donné, dans un établissement au profil donné. */
function membre(string $role, string $profil = 'centre_sante'): User
{
    $etab = Etablissement::factory()->create(['profil' => $profil, 'type' => null, 'modules' => null]);

    return User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role]);
}

it('donne à chaque rôle ses droits par défaut, limités aux modules actifs', function () {
    $pharmacien = membre('pharmacien', 'pharmacie');
    $medecin = membre('medecin', 'pharmacie'); // une pharmacie n'a pas de module consultation

    expect($pharmacien->permissions())->toContain('pharmacie.delivrer', 'stock.gerer')
        ->not->toContain('consultation.faire')
        ->and($medecin->permissions())->toContain('patients.voir')
        ->not->toContain('consultation.faire');
});

it('renvoie les droits du membre avec /me', function () {
    $this->actingAs(membre('caissier'), 'api')
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('etablissement.profil', 'centre_sante')
        ->assertJsonFragment(['caisse.encaisser']);
});

it("laisse l'admin ajuster les droits d'un rôle", function () {
    $admin = membre('admin_etablissement');
    $caissier = User::factory()->create(['etablissement_id' => $admin->etablissement_id, 'role' => 'caissier']);

    $this->actingAs($admin, 'api')
        ->putJson('/api/droits/caissier', ['permissions' => ['patients.voir', 'caisse.encaisser', 'caisse.rapports']])
        ->assertOk();

    expect(User::find($caissier->id)->permissions())->toContain('caisse.rapports')->not->toContain('file.emettre');

    $this->actingAs($admin, 'api')->deleteJson('/api/droits/caissier')->assertOk();
    expect(User::find($caissier->id)->permissions())->toContain('file.emettre');
});

it("ne laisse pas modifier les droits d'un rôle d'admin", function () {
    $this->actingAs(membre('admin_etablissement'), 'api')
        ->putJson('/api/droits/admin_etablissement', ['permissions' => []])
        ->assertStatus(422);
});

it("refuse les réglages de droits à qui n'a pas le droit", function () {
    $this->actingAs(membre('medecin'), 'api')->getJson('/api/droits')->assertForbidden();
});

it("liste les rôles et ce qu'ils peuvent faire", function () {
    $resp = $this->actingAs(membre('admin_etablissement', 'pharmacie'), 'api')->getJson('/api/droits')->assertOk();

    $labo = collect($resp->json('permissions'))->firstWhere('code', 'labo.saisir');
    expect($labo['disponible'])->toBeFalse()
        ->and(collect($resp->json('roles'))->pluck('role'))->toContain('accueil')->not->toContain('admin');
});

it("active un module : ses droits s'ouvrent aussitôt", function () {
    $admin = membre('admin_etablissement', 'pharmacie');
    $laborantin = User::factory()->create(['etablissement_id' => $admin->etablissement_id, 'role' => 'laborantin']);
    expect($laborantin->permissions())->not->toContain('labo.saisir');

    $this->actingAs($admin, 'api')
        ->patchJson('/api/etablissements/'.$admin->etablissement_id, ['modules' => ['file', 'caisse', 'pharmacie', 'labo']])
        ->assertOk();

    expect(User::find($laborantin->id)->permissions())->toContain('labo.saisir');
});
