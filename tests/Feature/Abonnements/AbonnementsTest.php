<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00')));
afterEach(fn () => Carbon::setTestNow());

/** Un centre de santé et son admin, connecté. */
function centreAbonne(): array
{
    $etab = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']);
    test()->actingAs($admin, 'api');

    return [$etab, $admin];
}

/** L'équipe Ngoni Santé passe par la Console Ngoni : sa clé, son opérateur. */
function enteteConsole(): array
{
    config(['sante.console.cle' => 'cle-de-test']);

    return ['Authorization' => 'Bearer cle-de-test', 'X-Console-Operateur' => 'Ismaila'];
}

it('commence par un essai de 14 jours avec tout', function () {
    [$etab] = centreAbonne();

    $etat = $this->getJson('/api/abonnement')->assertOk()->json();
    expect($etat['abonnement']['formule'])->toBe('essai')
        ->and($etat['abonnement']['en_cours'])->toBeTrue()
        ->and($etat['abonnement']['fin'])->toBe('2026-11-16')
        ->and(collect($etat['formules'])->pluck('code')->all())->toBe(['essentiel', 'pro', 'hopital']);
    $this->getJson('/api/rapports/mensuel')->assertOk();
});

it('ferme ce que la formule n’inclut pas : le labo et le rapport en Essentiel', function () {
    [$etab, $admin] = centreAbonne();
    Abonnement::where('etablissement_id', $etab->id)->update(['formule' => 'essentiel', 'fin' => '2026-12-01']);

    expect($admin->fresh()->permissions())->not->toContain('labo.saisir')->not->toContain('maternite.suivre')
        ->toContain('consultation.faire');
    $this->getJson('/api/rapports/mensuel')->assertForbidden()->assertJson(['code' => 'FONCTION_NON_INCLUSE']);
    $this->getJson('/api/patients')->assertOk();
});

it('échu : le soin continue, la gestion se ferme, le renouvellement reste ouvert', function () {
    [$etab] = centreAbonne();
    Carbon::setTestNow(Carbon::parse('2026-11-17 08:00:00'));

    $this->postJson('/api/patients', ['nom' => 'Awa Traoré', 'age' => 30])->assertCreated();
    expect($this->getJson('/api/me')->json('permissions'))->toContain('labo.saisir')->not->toContain('equipe.gerer');
    $this->postJson('/api/users', ['name' => 'Awa', 'telephone' => '76001111', 'role' => 'infirmier', 'pin' => '1234'])
        ->assertStatus(422);
    $this->getJson('/api/rapports/mensuel')->assertForbidden();
    expect($this->getJson('/api/abonnement')->assertOk()->json('abonnement.en_cours'))->toBeFalse();
});

it('limite l’équipe à la taille de la formule', function () {
    [$etab] = centreAbonne();
    Abonnement::where('etablissement_id', $etab->id)->update(['formule' => 'essentiel', 'fin' => '2026-12-01']);
    User::factory()->count(4)->create(['etablissement_id' => $etab->id, 'role' => 'infirmier']);

    $this->postJson('/api/users', ['name' => 'Awa', 'telephone' => '76001111', 'role' => 'infirmier', 'pin' => '1234'])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Votre formule permet 5 membres dans l’équipe. Passez à la formule au-dessus pour en ajouter.']);
});

it('paie, la console approuve : la formule court un mois, un reçu est numéroté', function () {
    [$etab] = centreAbonne();
    $demande = $this->postJson('/api/abonnement/demandes', [
        'formule' => 'pro', 'mois' => 1, 'moyen' => 'orange_money', 'telephone' => '76000000',
    ])->assertCreated()->json('demande');
    expect($demande['montant'])->toBe(15000)->and($demande['statut'])->toBe('en_attente');
    $this->postJson('/api/abonnement/demandes', ['formule' => 'pro', 'mois' => 1, 'moyen' => 'wave'])->assertStatus(422);

    expect($this->getJson('/api/console/v1/demandes', enteteConsole())->assertOk()->json('data.0.compte.nom'))->toBe($etab->nom);
    $ok = $this->postJson("/api/console/v1/demandes/{$demande['uuid']}/approuver", [], enteteConsole())->assertOk()->json('demande');

    expect($ok['statut'])->toBe('approuvee')
        ->and($ok['recu_numero'])->toMatch('/^A26-\d{4}$/')
        ->and($ok['periode_debut'])->toBe('2026-11-02')
        ->and($ok['periode_fin'])->toBe('2026-12-01')
        ->and(Abonnement::where('etablissement_id', $etab->id)->value('formule'))->toBe('pro');
});

it('renouveler la même formule prolonge à partir de la fin', function () {
    [$etab] = centreAbonne();
    Abonnement::where('etablissement_id', $etab->id)->update(['formule' => 'pro', 'fin' => '2026-11-30']);
    $uuid = $this->postJson('/api/abonnement/demandes', ['formule' => 'pro', 'mois' => 3, 'moyen' => 'wave'])->json('demande.uuid');

    $ok = $this->postJson("/api/console/v1/demandes/{$uuid}/approuver", [], enteteConsole())->assertOk()->json('demande');
    expect($ok['periode_debut'])->toBe('2026-12-01')->and($ok['periode_fin'])->toBe('2027-02-28');
});

it('réserve la console à l’équipe Ngoni Santé, qui règle l’essai et les prix', function () {
    $this->patchJson('/api/console/v1/formules/essai', ['jours_essai' => 30])->assertUnauthorized();

    $this->patchJson('/api/console/v1/formules/essai', ['jours_essai' => 30], enteteConsole())->assertOk();
    $this->patchJson('/api/console/v1/formules/pro', ['tarifs' => [['mois' => 1, 'montant' => 12000]]], enteteConsole())->assertOk();

    $etab = Etablissement::factory()->create();
    expect(Abonnement::where('etablissement_id', $etab->id)->first()->fin->toDateString())->toBe('2026-12-02');
    $pro = collect($this->getJson('/api/console/v1/formules', enteteConsole())->json('data'))->firstWhere('code', 'pro');
    expect(collect($pro['tarifs'])->firstWhere('mois', 1)['montant'])->toBe(12000);
});

it('accorde une formule à la main, sans échéance', function () {
    $etab = Etablissement::factory()->create();
    $this->postJson("/api/console/v1/comptes/{$etab->uuid}/abonnement", ['formule' => 'hopital', 'fin' => null, 'note' => 'Partenaire'], enteteConsole())
        ->assertOk();
    expect(Abonnement::where('etablissement_id', $etab->id)->first())
        ->formule->toBe('hopital')->fin->toBeNull()->note->toBe('Partenaire');
});
