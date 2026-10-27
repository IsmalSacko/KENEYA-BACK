<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visite;
use App\Sante\File\Statut;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Un centre de santé, un caissier connecté, des tarifs. Sans [automatique],
 * le ticket de consultation n'est pas facturé tout seul : le test ajoute
 * ses actes lui-même.
 */
function caisse(bool $automatique = false): array
{
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']);
    test()->actingAs($admin, 'api');
    test()->getJson('/api/file/services');
    $consultation = test()->postJson('/api/tarifs', ['nom' => 'Consultation générale', 'categorie' => 'consultation', 'prix' => 2000])->json('tarif.uuid');
    $goutte = test()->postJson('/api/tarifs', ['nom' => 'Goutte épaisse', 'categorie' => 'labo', 'prix' => 1500])->json('tarif.uuid');
    if (! $automatique) {
        test()->patchJson('/api/file/services/'.Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'), ['tarif_uuid' => null]);
    }

    return [$etab, $admin, $consultation, $goutte];
}

function nouveauTicket(string $prefixe = 'A', array $plus = []): array
{
    return test()->postJson('/api/file/tickets', [
        'service_uuid' => Service::where('prefixe', $prefixe)->value('uuid'), 'nom' => 'Moussa', ...$plus,
    ])->json('ticket');
}

it('ouvre une visite au premier ticket, et la garde au transfert', function () {
    caisse();
    $t = nouveauTicket('A');
    $this->postJson('/api/file/appeler', ['services' => [Service::where('prefixe', 'A')->value('uuid')]]);
    $suivant = $this->postJson("/api/file/tickets/{$t['uuid']}/transferer", [
        'service_uuid' => Service::where('prefixe', 'C')->value('uuid'),
    ])->json('ticket');

    expect($t['visite_uuid'])->not->toBeNull()
        ->and($suivant['visite_uuid'])->toBe($t['visite_uuid'])
        ->and(Visite::count())->toBe(1);

    $visite = $this->getJson("/api/visites/{$t['visite_uuid']}")->json('visite');
    expect(collect($visite['tickets'])->pluck('code')->all())->toBe(['A001', 'C001']);
});

it('facture les actes du tarif et les lignes libres sur une seule facture', function () {
    [, , $consultation, $goutte] = caisse();
    $v = nouveauTicket()['visite_uuid'];

    $this->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation])->assertOk();
    $this->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $goutte, 'quantite' => 2])->assertOk();
    $visite = $this->postJson("/api/visites/{$v}/lignes", ['libelle' => 'Carnet', 'prix' => 500])->assertOk()->json('visite');

    expect($visite['total'])->toBe(2000 + 3000 + 500)->and($visite['reste'])->toBe(5500)->and($visite['lignes'])->toHaveCount(3);
});

it('encaisse en plusieurs fois, sans dépasser le reste, dans une caisse ouverte', function () {
    [, , $consultation] = caisse();
    $v = nouveauTicket()['visite_uuid'];
    $this->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation]);

    $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 1000, 'moyen' => 'especes'])
        ->assertStatus(422)->assertJsonPath('message', 'Ouvrez votre caisse avant d’encaisser.');

    $this->postJson('/api/caisse/ouvrir', ['fond_initial' => 5000])->assertOk();
    $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 1000, 'moyen' => 'especes'])->assertOk();
    $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 1500, 'moyen' => 'especes'])->assertStatus(422);
    $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 1000, 'moyen' => 'orange_money'])
        ->assertStatus(422)->assertJsonPath('message', 'Indiquez la référence de la transaction Mobile Money.');
    $fin = $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 1000, 'moyen' => 'orange_money', 'reference' => 'OM123456'])
        ->assertOk()->json('visite');

    expect($fin['reste'])->toBe(0)->and($fin['encaissements'])->toHaveCount(2);
});

it("refuse un moyen de paiement qui n'existe pas dans le pays", function () {
    [, , $consultation] = caisse();
    $v = nouveauTicket()['visite_uuid'];
    $this->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation]);
    $this->postJson('/api/caisse/ouvrir');

    $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 2000, 'moyen' => 'mpesa', 'reference' => 'X'])->assertStatus(422);
});

it("clôture la caisse avec l'écart entre espèces comptées et attendues", function () {
    [, , $consultation] = caisse();
    $this->postJson('/api/caisse/ouvrir', ['fond_initial' => 5000]);
    foreach (range(1, 2) as $_) {
        $v = nouveauTicket()['visite_uuid'];
        $this->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation]);
        $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 2000, 'moyen' => 'especes']);
    }

    $cloture = $this->postJson('/api/caisse/cloturer', ['especes_comptees' => 8500])->assertOk()->json();
    expect($cloture['especes_attendues'])->toBe(9000)->and($cloture['ecart'])->toBe(-500);
    $this->getJson('/api/caisse/session')->assertJsonPath('session', null);
});

it('fait le rapport du jour : facturé, encaissé par moyen, impayé', function () {
    [, , $consultation, $goutte] = caisse();
    $this->postJson('/api/caisse/ouvrir');
    $v1 = nouveauTicket()['visite_uuid'];
    $this->postJson("/api/visites/{$v1}/lignes", ['tarif_uuid' => $consultation]);
    $this->postJson("/api/visites/{$v1}/encaisser", ['montant' => 2000, 'moyen' => 'wave', 'reference' => 'W1']);
    $v2 = nouveauTicket()['visite_uuid'];
    $this->postJson("/api/visites/{$v2}/lignes", ['tarif_uuid' => $goutte]);

    $rapport = $this->getJson('/api/caisse/rapport')->assertOk()->json();
    expect($rapport['facture'])->toBe(3500)
        ->and($rapport['encaisse'])->toBe(2000)
        ->and($rapport['impaye'])->toBe(1500)
        ->and(collect($rapport['par_moyen'])->firstWhere('moyen', 'wave')['total'])->toBe(2000)
        ->and(collect($rapport['par_categorie'])->firstWhere('categorie', 'labo')['total'])->toBe(1500);
});

it('ne retire pas une ligne déjà payée', function () {
    [, , $consultation] = caisse();
    $this->postJson('/api/caisse/ouvrir');
    $v = nouveauTicket()['visite_uuid'];
    $ligne = $this->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation])->json('visite.lignes.0.uuid');
    $this->postJson("/api/visites/{$v}/encaisser", ['montant' => 2000, 'moyen' => 'especes']);

    $this->deleteJson("/api/visites/{$v}/lignes/{$ligne}")->assertStatus(422);
});

it('respecte les droits : le médecin facture un acte, seul le caissier encaisse', function () {
    [$etab, , $consultation] = caisse();
    $v = nouveauTicket()['visite_uuid'];
    $medecin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'medecin']);

    $this->actingAs($medecin, 'api')->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation])->assertOk();
    $this->actingAs($medecin, 'api')->postJson('/api/caisse/ouvrir')->assertForbidden();
    $this->actingAs($medecin, 'api')->postJson('/api/tarifs', ['nom' => 'X', 'prix' => 1])->assertForbidden();
});

it('montre à la personne ses visites et ce qu’elle a payé', function () {
    [$etab, $admin, $consultation] = caisse();
    $token = $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '70000099', 'nom' => 'Awa', 'pin' => '1234'])->json('access_token');
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    $service = Service::where('prefixe', 'A')->value('uuid');
    $this->withToken($token)->postJson('/api/compte/tickets', ['service_uuid' => $service])->assertStatus(201);
    $v = Ticket::first()->visite->uuid;
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    $this->actingAs($admin, 'api')->postJson("/api/visites/{$v}/lignes", ['tarif_uuid' => $consultation]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');

    $mes = $this->withToken($token)->getJson('/api/compte/visites')->assertOk()->json('data');
    expect($mes)->toHaveCount(1)->and($mes[0]['total'])->toBe(2000)->and($mes[0]['etablissement'])->toBe($etab->nom);
});

it('facture le ticket de consultation dès l’accueil, une seule fois par visite', function () {
    caisse(true); // le tarif « consultation » se relie au service Consultation
    $t = nouveauTicket('A');
    expect(Visite::where('uuid', $t['visite_uuid'])->value('total'))->toBe(2000);

    // Revenu en consultation le même jour : pas facturé deux fois.
    $this->postJson('/api/file/appeler', ['services' => [Service::where('prefixe', 'A')->value('uuid')]]);
    $this->postJson("/api/file/tickets/{$t['uuid']}/transferer", ['service_uuid' => Service::where('prefixe', 'A')->value('uuid')]);
    expect(Visite::where('uuid', $t['visite_uuid'])->value('total'))->toBe(2000);

    // Le service peut changer d'acte, ou ne plus en facturer.
    $this->patchJson('/api/file/services/'.Service::where('prefixe', 'C')->value('uuid'), ['tarif_uuid' => null])->assertOk();
    $this->patchJson('/api/file/services/'.Service::where('prefixe', 'A')->value('uuid'), ['tarif_uuid' => null])->assertOk();
    expect(nouveauTicket('A', ['nom' => 'Awa'])['visite_uuid'])->not->toBe($t['visite_uuid'])
        ->and(Visite::latest('id')->value('total'))->toBe(0);
});

it('sort le patient de la file de la caisse une fois tout payé', function () {
    caisse(true);
    $t = nouveauTicket('A');
    $c = $this->postJson("/api/file/tickets/{$t['uuid']}/transferer", [
        'service_uuid' => Service::where('prefixe', 'C')->value('uuid'),
    ])->json('ticket');
    $this->postJson('/api/caisse/ouvrir');

    $this->postJson("/api/visites/{$t['visite_uuid']}/encaisser", ['montant' => 1000, 'moyen' => 'especes']);
    expect(Ticket::where('uuid', $c['uuid'])->value('statut'))->toBe(Statut::Emis);
    $this->postJson("/api/visites/{$t['visite_uuid']}/encaisser", ['montant' => 1000, 'moyen' => 'especes']);
    expect(Ticket::where('uuid', $c['uuid'])->value('statut'))->toBe(Statut::Termine);
});
