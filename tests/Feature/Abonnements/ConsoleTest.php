<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['sante.console.cle' => 'cle-de-test']));

function console(): array
{
    return ['Authorization' => 'Bearer cle-de-test', 'X-Console-Operateur' => 'Ismaila'];
}

it('ferme la console sans la bonne clé', function () {
    $this->getJson('/api/console/v1/resume')->assertUnauthorized();
    $this->getJson('/api/console/v1/resume', ['Authorization' => 'Bearer autre'])->assertUnauthorized();
    config(['sante.console.cle' => '']);
    $this->getJson('/api/console/v1/resume', ['Authorization' => 'Bearer '])->assertUnauthorized();
});

it('résume Ngoni Santé selon le contrat', function () {
    Etablissement::factory()->count(2)->create();

    $this->getJson('/api/console/v1/resume', console())->assertOk()
        ->assertJsonPath('application.code', 'ngoni-sante')
        ->assertJsonPath('comptes', 2)
        ->assertJsonPath('essais', 2)
        ->assertJsonStructure(['a_valider', 'nouveaux_30j', 'essais_bientot_finis', 'abonnes', 'echus', 'demandes_en_attente', 'encaisse_mois']);
});

it('liste les comptes, accorde une formule, valide', function () {
    $e = Etablissement::factory()->create(['nom' => 'CSCom de Banconi', 'valide_at' => null]);

    $compte = $this->getJson('/api/console/v1/comptes?filtre=a_valider', console())->assertOk()->json('data.0');
    expect($compte['id'])->toBe($e->uuid)->and($compte['formule'])->toBe('essai')->and($compte['valide'])->toBeFalse();

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/valider", [], console())->assertOk();
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/abonnement", ['formule' => 'pro', 'fin' => null], console())->assertOk();
    expect(Abonnement::where('etablissement_id', $e->id)->first())->formule->toBe('pro')->fin->toBeNull();
});

it('approuve une demande et règle les prix', function () {
    $e = Etablissement::factory()->create();
    $d = DemandeAbonnement::create([
        'etablissement_id' => $e->id, 'formule' => 'pro', 'mois' => 1, 'montant' => 15000,
        'devise' => 'XOF', 'moyen' => 'wave', 'statut' => 'en_attente',
    ]);

    $liste = $this->getJson('/api/console/v1/demandes', console())->assertOk()->json('data');
    expect($liste[0]['id'])->toBe($d->uuid)->and($liste[0]['compte']['nom'])->toBe($e->nom);
    $this->postJson("/api/console/v1/demandes/{$d->uuid}/approuver", [], console())->assertOk()
        ->assertJsonPath('demande.statut', 'approuvee');

    $pro = collect($this->patchJson('/api/console/v1/formules/pro', ['tarifs' => [['mois' => 1, 'montant' => 12000]]], console())
        ->assertOk()->json('data'))->firstWhere('code', 'pro');
    expect(collect($pro['tarifs'])->firstWhere('mois', 1)['montant'])->toBe(12000)->and($pro['inclus'])->toContain('Laboratoire');
});

it('valide à un montant négocié : il remplace le montant demandé, gardé à côté', function () {
    $e = Etablissement::factory()->create();
    $d = DemandeAbonnement::create([
        'etablissement_id' => $e->id, 'formule' => 'pro', 'mois' => 1, 'montant' => 15000,
        'devise' => 'XOF', 'moyen' => 'wave', 'statut' => 'en_attente',
    ]);

    $this->postJson("/api/console/v1/demandes/{$d->uuid}/approuver", ['montant' => 13000, 'note' => 'Remise négociée'], console())->assertOk()
        ->assertJsonPath('demande.montant', 13000)
        ->assertJsonPath('demande.montant_demande', 15000)
        ->assertJsonPath('demande.note_validation', 'Remise négociée');
});

it('laisse les établissements de l’éditeur hors des chiffres, et les marque d’un geste', function () {
    $client = Etablissement::factory()->create(['nom' => 'CSCom de Banconi']);
    $demo = Etablissement::factory()->create(['nom' => 'Démo', 'interne' => true]);

    $this->getJson('/api/console/v1/resume', console())->assertJsonPath('comptes', 1)->assertJsonPath('internes', 1);
    expect(collect($this->getJson('/api/console/v1/comptes', console())->json('data'))->pluck('nom')->all())->toBe(['CSCom de Banconi']);
    expect($this->getJson('/api/console/v1/comptes?filtre=interne', console())->json('data.0.nom'))->toBe('Démo');

    $this->postJson("/api/console/v1/comptes/{$client->uuid}/interne", ['interne' => true], console())->assertOk();
    $this->getJson('/api/console/v1/resume', console())->assertJsonPath('comptes', 0)->assertJsonPath('internes', 2);
});

