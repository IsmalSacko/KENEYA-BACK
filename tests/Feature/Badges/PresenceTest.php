<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function membreClinique(Etablissement $etab, string $role, string $nom = ''): User
{
    $m = User::where(['etablissement_id' => $etab->id, 'role' => $role])->first()
        ?? User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role, 'name' => $nom !== '' ? $nom : ucfirst($role)]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($m, 'api');

    return $m;
}

function cliniqueAvecBadges(): Etablissement
{
    $etab = Etablissement::factory()->create(['nom' => 'Clinique Pasteur', 'profil' => 'clinique', 'type' => null, 'modules' => null]);
    membreClinique($etab, 'infirmier', 'Kadiatou');
    membreClinique($etab, 'admin_etablissement', 'Directeur');

    return $etab;
}

it('pointe arrivée puis départ avec le badge, et compte les heures du mois', function () {
    Carbon::setTestNow('2026-10-27 07:55');
    $etab = cliniqueAvecBadges();
    $badges = collect($this->getJson('/api/badges')->assertOk()->json('data'))->keyBy('nom');
    $badge = $badges['Kadiatou']['badge'];
    expect($badge)->toStartWith('ngoni-sante:badge:');

    membreClinique($etab, 'accueil'); // la pointeuse de l'entrée
    $this->postJson('/api/pointages', ['qr' => $badge])->assertOk()->assertJsonPath('pointage.type', 'arrivee');
    $this->postJson('/api/pointages', ['qr' => $badge])->assertOk()->assertJsonPath('pointage.type', 'arrivee'); // double scan
    expect($this->getJson('/api/pointages/presents')->json('data.0.nom'))->toBe('Kadiatou');

    Carbon::setTestNow('2026-10-27 16:25');
    $this->postJson('/api/pointages', ['qr' => $badge])->assertOk()->assertJsonPath('pointage.type', 'depart');
    expect($this->getJson('/api/pointages/presents')->json('data'))->toBe([]);

    membreClinique($etab, 'admin_etablissement');
    $kadi = collect($this->getJson('/api/pointages/rapport?mois=2026-10')->json('data'))->firstWhere('nom', 'Kadiatou');
    expect($kadi['jours'])->toBe(1)->and($kadi['heures'])->toBe(8.5);
});

it('refuse un badge perdu après renouvellement, et celui d’un autre établissement', function () {
    $etab = cliniqueAvecBadges();
    $kadi = collect($this->getJson('/api/badges')->json('data'))->firstWhere('nom', 'Kadiatou');
    $nouveau = $this->postJson("/api/badges/{$kadi['uuid']}/renouveler")->assertOk()->json('membre.badge');

    $this->postJson('/api/pointages', ['qr' => $kadi['badge']])->assertStatus(422)->assertJsonPath('message', 'Badge inconnu ici.');
    $this->postJson('/api/pointages', ['qr' => $nouveau])->assertOk();

    $autre = cliniqueAvecBadges();
    $this->postJson('/api/pointages', ['qr' => $nouveau])->assertStatus(422);
});

it('n’ouvre les badges qu’aux établissements qui ont le module', function () {
    $etab = Etablissement::factory()->create(['profil' => 'cabinet_medical', 'type' => null, 'modules' => null]);
    membreClinique($etab, 'accueil');
    $this->postJson('/api/pointages', ['qr' => 'ngoni-sante:badge:xxxxxxxx'])->assertForbidden();
});

it('enregistre les visiteurs d’un patient, deux au plus à la fois', function () {
    $etab = cliniqueAvecBadges();
    $this->getJson('/api/file/services');
    $this->postJson('/api/hospitalisation/chambres', ['nom' => 'Salle 1', 'lits' => 2]);
    $lit = $this->getJson('/api/hospitalisation/chambres')->json('data.0.lits.0.uuid');
    membreClinique($etab, 'accueil');
    $visite = $this->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'), 'nom' => 'Oumar Traoré',
    ])->json('ticket.visite_uuid');
    membreClinique($etab, 'medecin');
    $sejour = $this->postJson("/api/visites/{$visite}/hospitalisation", ['lit_uuid' => $lit, 'motif' => 'Observation'])->json('sejour.uuid');

    membreClinique($etab, 'accueil');
    expect($this->getJson('/api/visiteurs/patients')->assertOk()->json('data.0'))->toBe([
        'uuid' => $sejour, 'patient' => 'Oumar Traoré', 'lit' => 'Salle 1 · lit 1', 'visiteurs' => 0,
    ]);
    $v1 = $this->postJson('/api/visiteurs', ['nom' => 'Fatou', 'sejour_uuid' => $sejour])->assertStatus(201)->json('visiteur');
    expect($v1['patient'])->toBe('Oumar Traoré')->and($v1['lit'])->toBe('Salle 1 · lit 1');
    $this->postJson('/api/visiteurs', ['nom' => 'Ali', 'sejour_uuid' => $sejour])->assertStatus(201);
    $this->postJson('/api/visiteurs', ['nom' => 'Sali', 'sejour_uuid' => $sejour])->assertStatus(422);

    $this->postJson('/api/visiteurs/sortie', ['qr' => $v1['qr']])->assertOk()->assertJsonPath('visiteur.nom', 'Fatou');
    $this->postJson('/api/visiteurs', ['nom' => 'Sali', 'sejour_uuid' => $sejour])->assertStatus(201);
    expect($this->getJson('/api/visiteurs')->json('data'))->toHaveCount(2);
});
