<?php

declare(strict_types=1);

use App\Models\Consultation;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Crée un établissement + un utilisateur admin authentifié.
 */
function actingUser(?Etablissement $etab = null): User
{
    $etab ??= Etablissement::factory()->create();
    $user = User::factory()->create(['etablissement_id' => $etab->id]);
    test()->actingAs($user, 'api');

    return $user;
}

function push(array $changes)
{
    return test()->postJson('/api/sync/push', ['changes' => $changes]);
}

function pull(int $lastRev = 0)
{
    return test()->postJson('/api/sync/pull', ['last_pulled_rev' => $lastRev]);
}

// ---------------------------------------------------------------------------
// PULL
// ---------------------------------------------------------------------------

it('pull première synchro renvoie le vivant du bon établissement seulement', function () {
    $user = actingUser();
    $autre = Etablissement::factory()->create();

    Patient::factory()->count(2)->create(['etablissement_id' => $user->etablissement_id]);
    Patient::factory()->create(['etablissement_id' => $autre->id]); // hors périmètre

    $resp = pull(0)->assertOk();

    expect($resp->json('changes.patients.upserted'))->toHaveCount(2);
    expect($resp->json('server_rev'))->toBeGreaterThan(0);
});

it('pull delta ne renvoie que les révisions au-delà du curseur', function () {
    $user = actingUser();
    Patient::factory()->create(['etablissement_id' => $user->etablissement_id]);

    $rev = pull(0)->json('server_rev');

    // Aucun changement depuis -> vide
    expect(pull($rev)->json('changes.patients.upserted'))->toHaveCount(0);

    // Nouveau patient -> apparaît dans le delta
    Patient::factory()->create(['etablissement_id' => $user->etablissement_id]);
    expect(pull($rev)->json('changes.patients.upserted'))->toHaveCount(1);
});

it('pull renvoie les tombstones des suppressions', function () {
    $user = actingUser();
    $patient = Patient::factory()->create(['etablissement_id' => $user->etablissement_id]);
    $rev = pull(0)->json('server_rev');

    $patient->delete();

    $resp = pull($rev)->assertOk();
    expect($resp->json('changes.patients.deleted'))->toContain($patient->uuid);
});

// ---------------------------------------------------------------------------
// PUSH — idempotence & création
// ---------------------------------------------------------------------------

it('push crée un patient scopé au JWT, de façon idempotente', function () {
    $user = actingUser();
    $uuid = (string) Str::uuid();

    $payload = ['patients' => ['upserted' => [
        ['uuid' => $uuid, 'nom' => 'Awa', 'telephone' => '70000000', 'adresse' => 'Bamako'],
    ]]];

    push($payload)->assertOk()->assertJsonPath('accepted', true);
    push($payload)->assertOk(); // rejeu

    expect(Patient::where('uuid', $uuid)->count())->toBe(1);
    $patient = Patient::where('uuid', $uuid)->firstOrFail();
    expect($patient->etablissement_id)->toBe($user->etablissement_id);
    expect($patient->nom)->toBe('Awa');
});

it('push ignore un etablissement_id étranger et force celui du JWT', function () {
    $user = actingUser();
    $autre = Etablissement::factory()->create();
    $uuid = (string) Str::uuid();

    push(['patients' => ['upserted' => [[
        'uuid' => $uuid,
        'nom' => 'Moussa',
        'etablissement_id' => $autre->id, // tentative d'injection
    ]]]])->assertOk();

    expect(Patient::where('uuid', $uuid)->firstOrFail()->etablissement_id)->toBe($user->etablissement_id);
});

// ---------------------------------------------------------------------------
// PUSH — clés étrangères
// ---------------------------------------------------------------------------

it('push résout les clés étrangères par uuid parent', function () {
    $user = actingUser();
    $patient = Patient::factory()->create(['etablissement_id' => $user->etablissement_id]);
    $uuid = (string) Str::uuid();

    push(['consultations' => ['upserted' => [[
        'uuid' => $uuid,
        'patient_uuid' => $patient->uuid,
        'medecin_uuid' => $user->uuid,
        'motif' => 'Fièvre',
        'montant' => 5000,
        'statut' => 'en_attente',
    ]]]])->assertOk()->assertJsonPath('accepted', true);

    $cons = Consultation::where('uuid', $uuid)->firstOrFail();
    expect($cons->patient_id)->toBe($patient->id);
    expect($cons->medecin_id)->toBe($user->id);
});

it('push rejette un enfant dont le parent est absent, sans bloquer le reste', function () {
    $user = actingUser();
    $bonPatient = (string) Str::uuid();
    $consAvecParentManquant = (string) Str::uuid();

    $resp = push([
        'patients' => ['upserted' => [['uuid' => $bonPatient, 'nom' => 'Fanta']]],
        'consultations' => ['upserted' => [[
            'uuid' => $consAvecParentManquant,
            'patient_uuid' => (string) Str::uuid(), // inconnu
            'medecin_uuid' => $user->uuid,
            'motif' => 'X', 'montant' => 1000, 'statut' => 'en_attente',
        ]]],
    ])->assertOk();

    expect($resp->json('accepted'))->toBeFalse();
    expect($resp->json('rejected.0.reason'))->toBe('missing_parent');
    expect(Patient::where('uuid', $bonPatient)->exists())->toBeTrue(); // le reste passe
    expect(Consultation::where('uuid', $consAvecParentManquant)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// PUSH — sécurité multi-tenant
// ---------------------------------------------------------------------------

it('push refuse d\'écraser un uuid appartenant à un autre établissement', function () {
    $user = actingUser();
    $autre = Etablissement::factory()->create();
    $patientAutre = Patient::factory()->create(['etablissement_id' => $autre->id, 'nom' => 'Original']);

    $resp = push(['patients' => ['upserted' => [[
        'uuid' => $patientAutre->uuid,
        'nom' => 'Pirate',
    ]]]])->assertOk();

    expect($resp->json('rejected.0.reason'))->toBe('scope_violation');
    expect($patientAutre->fresh()->nom)->toBe('Original');
});

// ---------------------------------------------------------------------------
// STOCK — ledger dérivé, ventes concurrentes
// ---------------------------------------------------------------------------

it('applique les mouvements de stock comme deltas (aucune perte concurrente)', function () {
    $user = actingUser();
    $medicament = Medicament::factory()->create([
        'etablissement_id' => $user->etablissement_id,
        'stock' => 100,
    ]);

    push(['mouvement_stocks' => ['upserted' => [
        ['uuid' => (string) Str::uuid(), 'medicament_uuid' => $medicament->uuid, 'type' => 'sortie', 'quantite' => 30],
        ['uuid' => (string) Str::uuid(), 'medicament_uuid' => $medicament->uuid, 'type' => 'sortie', 'quantite' => 20],
    ]]])->assertOk();

    expect($medicament->fresh()->stock)->toBe(50);
});

it('le rejeu d\'un mouvement de stock ne double pas le delta', function () {
    $user = actingUser();
    $medicament = Medicament::factory()->create([
        'etablissement_id' => $user->etablissement_id,
        'stock' => 100,
    ]);
    $mvtUuid = (string) Str::uuid();

    $payload = ['mouvement_stocks' => ['upserted' => [
        ['uuid' => $mvtUuid, 'medicament_uuid' => $medicament->uuid, 'type' => 'sortie', 'quantite' => 10],
    ]]];

    push($payload)->assertOk();
    push($payload)->assertOk(); // rejeu

    expect($medicament->fresh()->stock)->toBe(90);
    expect(MouvementStock::where('uuid', $mvtUuid)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// POLYMORPHE
// ---------------------------------------------------------------------------

it('résout une référence polymorphe (paiement -> consultation)', function () {
    $user = actingUser();
    $patient = Patient::factory()->create(['etablissement_id' => $user->etablissement_id]);
    $consultation = Consultation::factory()->create([
        'etablissement_id' => $user->etablissement_id,
        'patient_id' => $patient->id,
        'medecin_id' => $user->id,
    ]);

    push(['paiements' => ['upserted' => [[
        'uuid' => (string) Str::uuid(),
        'source_type' => 'consultation',
        'source_uuid' => $consultation->uuid,
        'montant' => 5000,
        'mode_paiement' => 'espece',
    ]]]])->assertOk()->assertJsonPath('accepted', true);

    expect(Paiement::query()->firstOrFail()->source_id)->toBe($consultation->id);
});

it('tolère un journal d\'audit dont la cible n\'est pas résolvable', function () {
    $user = actingUser();

    push(['journal_audits' => ['upserted' => [[
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $user->uuid,
        'action' => 'suppression_patient',
        'type_cible' => 'Patient',
        'cible_uuid' => (string) Str::uuid(), // jamais synchronisée
    ]]]])->assertOk()->assertJsonPath('accepted', true);

    expect(JournalAudit::query()->firstOrFail()->id_cible)->toBeNull();
});

// ---------------------------------------------------------------------------
// AUTH multi-établissement & GÉOLOC
// ---------------------------------------------------------------------------

it('login exige de préciser l\'établissement si le numéro est partagé', function () {
    $etabA = Etablissement::factory()->create();
    $etabB = Etablissement::factory()->create();
    User::factory()->create(['etablissement_id' => $etabA->id, 'telephone' => '76000000', 'pin' => '1234']);
    User::factory()->create(['etablissement_id' => $etabB->id, 'telephone' => '76000000', 'pin' => '1234']);

    $this->postJson('/api/login', ['telephone' => '76000000', 'pin' => '1234'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'etablissement_requis');

    $this->postJson('/api/login', [
        'telephone' => '76000000', 'pin' => '1234', 'etablissement_uuid' => $etabB->uuid,
    ])->assertOk()->assertJsonStructure(['access_token']);
});

it('expose une URL d\'itinéraire Google Maps quand les coordonnées existent', function () {
    $etab = Etablissement::factory()->create(['latitude' => 12.6392, 'longitude' => -8.0029]);

    expect($etab->itineraire_url)
        ->toContain('https://www.google.com/maps/dir/?api=1&destination=')
        ->toContain('12.639');

    $sansCoords = Etablissement::factory()->create(['latitude' => null, 'longitude' => null]);
    expect($sansCoords->itineraire_url)->toBeNull();
});
