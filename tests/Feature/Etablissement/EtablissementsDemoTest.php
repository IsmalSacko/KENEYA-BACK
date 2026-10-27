<?php

declare(strict_types=1);

use App\Models\Agenda;
use App\Models\Etablissement;
use App\Models\Lot;
use App\Models\Personne;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('crée un établissement par secteur, avec l’équipe et le nécessaire, sans doublon au second passage', function () {
    $this->artisan('sante:etablissements-demo')->assertSuccessful();
    $compter = fn (): array => [Etablissement::count(), User::count(), Personne::count(), Service::count(), Agenda::count(), Lot::count()];
    $avant = $compter();
    $this->artisan('sante:etablissements-demo')->assertSuccessful();
    expect($compter())->toBe($avant)->and(Etablissement::count())->toBe(8);

    // L'admin a un espace dans les 8, le médecin là où l'on consulte.
    $admin = $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '73136789', 'pin' => '1234'])->assertOk();
    expect($admin->json('espaces'))->toHaveCount(8)
        ->and(collect($admin->json('espaces'))->pluck('role')->unique()->all())->toBe(['admin_etablissement']);
    $medecin = $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '74988201', 'pin' => '1234'])->assertOk();
    expect($medecin->json('espaces'))->toHaveCount(5)
        ->and($medecin->json('personne.nom'))->toBe('Aïcha NIAFO');

    // Le CSCom marche dès l'ouverture : consultation facturée, labo tarifé, pharmacie en stock.
    $cscom = Etablissement::where('profil', 'centre_sante')->sole();
    expect(Service::where('etablissement_id', $cscom->id)->where('nom', 'Consultation')->value('tarif_id'))->not->toBeNull()
        ->and(Lot::where('etablissement_id', $cscom->id)->sum('quantite'))->toBeGreaterThan(0)
        ->and(User::where('etablissement_id', $cscom->id)->pluck('role')->sort()->values()->all())->toBe([
            'accueil', 'admin_etablissement', 'caissier', 'gestionnaire', 'infirmier', 'laborantin', 'medecin', 'pharmacien', 'sage_femme',
        ]);
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '70000202', 'pin' => '1234'])
        ->assertOk()->assertJsonPath('espaces.0.role', 'infirmier');
});

it('garde le PIN de quelqu’un qui a déjà un compte, sauf avec --pins', function () {
    $this->postJson('/api/compte/inscription', ['pays_code' => 'ML', 'telephone' => '73136789', 'nom' => 'ismo', 'pin' => '9999'])->assertCreated();
    $this->artisan('sante:etablissements-demo')->assertSuccessful();
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '73136789', 'pin' => '9999'])->assertOk();

    $this->artisan('sante:etablissements-demo --pins')->assertSuccessful();
    $this->postJson('/api/compte/connexion', ['pays_code' => 'ML', 'telephone' => '73136789', 'pin' => '1234'])
        ->assertOk()->assertJsonPath('personne.nom', 'Ismaila SACKO');
});
