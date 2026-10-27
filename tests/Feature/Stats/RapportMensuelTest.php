<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('compte l’activité du mois : patients, consultations par âge et sexe, diagnostics, recettes', function () {
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']);
    $this->actingAs($admin, 'api');
    $this->getJson('/api/file/services');
    $this->getJson('/api/tarifs');
    $service = Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid');

    foreach ([['Awa', 'F', 3, 'Paludisme simple'], ['Moussa', 'M', 40, 'Paludisme simple'], ['Fanta', 'F', 25, 'IRA']] as [$nom, $sexe, $age, $diag]) {
        $p = $this->postJson('/api/patients', ['nom' => $nom, 'sexe' => $sexe, 'age' => $age])->json('patient');
        $t = $this->postJson('/api/file/tickets', ['service_uuid' => $service, 'dossier_uuid' => $p['uuid']])->json('ticket');
        $this->postJson("/api/visites/{$t['visite_uuid']}/consultation", ['motif' => 'Fièvre', 'diagnostic' => $diag])->assertSuccessful();
    }

    $r = $this->getJson('/api/rapports/mensuel')->assertOk();
    expect($r->json('frequentation.visites'))->toBe(3)
        ->and($r->json('frequentation.nouveaux_patients'))->toBe(3)
        ->and($r->json('consultations.total'))->toBe(3)
        ->and($r->json('consultations.par_age.1-4 ans.F'))->toBe(1)
        ->and($r->json('consultations.par_age.15-49 ans.M'))->toBe(1)
        ->and($r->json('diagnostics.0'))->toBe(['diagnostic' => 'Paludisme simple', 'cas' => 2])
        ->and($r->json('finances.facture'))->toBe(4500)
        ->and(collect($r->json('par_jour'))->sum('visites'))->toBe(3)
        ->and($r->json('precedent.visites'))->toBe(0);
    $this->getJson('/api/rapports/mensuel?mois=2026-13')->assertStatus(422);
});
