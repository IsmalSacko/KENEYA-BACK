<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function membreAssure(Etablissement $etab, string $role): User
{
    $m = User::where(['etablissement_id' => $etab->id, 'role' => $role])->first()
        ?? User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($m, 'api');

    return $m;
}

/** Une clinique, l'AMO à 70 %, une visite facturée 10 000. */
function visiteAssuree(): array
{
    $etab = Etablissement::factory()->create(['profil' => 'clinique', 'type' => null, 'modules' => null]);
    membreAssure($etab, 'admin_etablissement');
    test()->getJson('/api/file/services');
    $amo = test()->postJson('/api/assureurs', ['nom' => 'AMO (CANAM)', 'taux' => 70])->assertStatus(201)->json('assureur.uuid');
    $mutuelle = test()->postJson('/api/assureurs', ['nom' => 'Mutuelle des enseignants', 'taux' => 80, 'plafond' => 5000])->json('assureur.uuid');
    $acte = test()->postJson('/api/tarifs', ['nom' => 'Échographie', 'categorie' => 'acte', 'prix' => 10000])->json('tarif.uuid');
    membreAssure($etab, 'caissier');
    $visite = test()->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'), 'nom' => 'Ibrahim Keïta',
    ])->json('ticket.visite_uuid');
    test()->postJson("/api/visites/{$visite}/lignes", ['tarif_uuid' => $acte])->assertOk();
    test()->postJson('/api/caisse/ouvrir');

    return [$etab, $visite, $amo, $mutuelle];
}

it('retire la part de l’assureur de ce que le patient paie', function () {
    [, $visite, $amo] = visiteAssuree();
    $v = $this->postJson("/api/visites/{$visite}/assurance", ['assureur_uuid' => $amo, 'numero' => 'AMO-123'])->assertOk()->json('visite');

    expect($v['part_assureur'])->toBe(7000)->and($v['reste'])->toBe(3000)
        ->and($v['assurance'])->toBe(['nom' => 'AMO (CANAM)', 'taux' => 70, 'numero' => 'AMO-123']);
    $this->postJson("/api/visites/{$visite}/encaisser", ['montant' => 3500, 'moyen' => 'especes'])->assertStatus(422);
    $this->postJson("/api/visites/{$visite}/encaisser", ['montant' => 3000, 'moyen' => 'especes'])->assertOk()->assertJsonPath('visite.reste', 0);
});

it('applique le plafond et suit les actes ajoutés ensuite', function () {
    [, $visite, , $mutuelle] = visiteAssuree();
    $v = $this->postJson("/api/visites/{$visite}/assurance", ['assureur_uuid' => $mutuelle, 'numero' => 'M-9'])->json('visite');
    expect($v['part_assureur'])->toBe(5000)->and($v['reste'])->toBe(5000); // 80 % = 8 000, plafond 5 000

    $v = $this->postJson("/api/visites/{$visite}/lignes", ['libelle' => 'Carnet', 'prix' => 500])->json('visite');
    expect($v['part_assureur'])->toBe(5000)->and($v['reste'])->toBe(5500);
});

it('refuse de couvrir après coup ce que le patient a déjà payé', function () {
    [, $visite, $amo] = visiteAssuree();
    $this->postJson("/api/visites/{$visite}/encaisser", ['montant' => 10000, 'moyen' => 'especes'])->assertOk();

    $this->postJson("/api/visites/{$visite}/assurance", ['assureur_uuid' => $amo, 'numero' => 'X'])
        ->assertStatus(422)->assertJsonPath('message', 'Le patient a déjà payé plus que sa part : remboursez d’abord.');
});

it('sort le bordereau du mois pour l’assureur, en CSV aussi', function () {
    Carbon::setTestNow('2026-10-27 10:00');
    [$etab, $visite, $amo] = visiteAssuree();
    $this->postJson("/api/visites/{$visite}/assurance", ['assureur_uuid' => $amo, 'numero' => 'AMO-123']);

    membreAssure($etab, 'admin_etablissement');
    $b = $this->getJson("/api/assurances/bordereau?assureur={$amo}&mois=2026-10")->assertOk()->json();
    expect($b['total'])->toBe(7000)->and($b['lignes'][0]['patient'])->toBe('Ibrahim Keïta');

    $csv = $this->get("/api/assurances/bordereau?assureur={$amo}&mois=2026-10&format=csv")->assertOk()->streamedContent();
    expect($csv)->toStartWith("\u{FEFF}Date;")->toContain('"Ibrahim Keïta";AMO-123;10000;70;7000');
    expect($this->getJson('/api/caisse/rapport')->json('part_assureurs'))->toBe(7000);
});

it('n’ouvre les assurances qu’avec le module', function () {
    $etab = Etablissement::factory()->create(['profil' => 'cabinet_medical', 'type' => null, 'modules' => null]);
    membreAssure($etab, 'admin_etablissement');
    $this->getJson('/api/assureurs')->assertForbidden();
});
