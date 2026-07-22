<?php

declare(strict_types=1);

use App\Models\DemandeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('affiche la page publique de suppression', function () {
    $this->get('/suppression-compte')
        ->assertOk()
        ->assertSee('Suppression de compte');
});

it('enregistre une demande de suppression valide', function () {
    $this->post('/suppression-compte', [
        'type_compte' => 'patient',
        'telephone' => '76001122',
        'motif' => 'Je ne souhaite plus utiliser le service.',
        'confirmation' => '1',
    ])->assertRedirect(route('suppression-compte.show'));

    $this->assertDatabaseHas('demandes_suppression', [
        'telephone' => '76001122',
        'type_compte' => 'patient',
        'statut' => 'en_attente',
    ]);
});

it('rejette une demande sans confirmation', function () {
    $this->from('/suppression-compte')
        ->post('/suppression-compte', [
            'type_compte' => 'patient',
            'telephone' => '76001122',
        ])
        ->assertRedirect('/suppression-compte')
        ->assertSessionHasErrors('confirmation');

    expect(DemandeSuppression::count())->toBe(0);
});
