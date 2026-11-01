<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\AccesDossier;
use App\Models\DemandeAbonnement;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Personne;
use App\Models\TelephonePush;
use App\Models\User;
use App\Models\Visite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * La personne supprime son compte depuis l'application (règle 5.1.1(v) de
 * l'App Store) : ce qui part est dit avant, puis PIN et « SUPPRIMER ».
 */

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** Inscrit une personne au Mali ; renvoie son jeton. */
function inscrireAvantSuppression(string $telephone, bool $pro = true): string
{
    $jeton = test()->postJson('/api/compte/inscription', [
        'pays_code' => 'ML', 'telephone' => $telephone, 'nom' => 'Awa Traoré', 'pin' => '1234', 'pro' => $pro,
    ])->assertStatus(201)->json('access_token');
    oublierJetons();

    return (string) $jeton;
}

/** Comme une nouvelle requête : la librairie JWT garde sinon le dernier jeton. */
function oublierJetons(): void
{
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
}

/** Un établissement où ce numéro est membre (admin par défaut), éventuellement propriétaire. */
function etablissementDe(string $telephone, string $role = 'admin_etablissement', bool $proprietaire = true): Etablissement
{
    $etablissement = Etablissement::factory()->create(['nom' => 'Cabinet '.$telephone, 'pays_code' => 'ML']);
    User::factory()->create(['etablissement_id' => $etablissement->id, 'telephone' => $telephone, 'role' => $role]);
    if ($proprietaire) {
        $etablissement->forceFill(['proprietaire_id' => Personne::where('telephone', '+223'.$telephone)->value('id')])->save();
    }

    return $etablissement;
}

function supprimerCompte(string $jeton, array $donnees = ['pin' => '1234', 'confirmation' => 'SUPPRIMER'])
{
    $reponse = test()->withToken($jeton)->postJson('/api/compte/supprimer', $donnees);
    oublierJetons();

    return $reponse;
}

it('supprime le compte d’un patient sur PIN et SUPPRIMER ; le numéro peut se réinscrire', function () {
    $jeton = inscrireAvantSuppression('76123456', pro: false);
    $personne = Personne::where('telephone', '+22376123456')->sole();
    TelephonePush::create(['personne_id' => $personne->id, 'jeton' => 'jeton-push', 'plateforme' => 'ios']);
    // « Mon dossier » rempli par le patient seul : aucun établissement ne l'a tenu.
    Dossier::create(['personne_id' => $personne->id, 'nom' => 'Awa Traoré', 'telephone' => '+22376123456']);

    $this->withToken($jeton)->getJson('/api/compte/suppression')->assertOk()
        ->assertJsonPath('data.etablissements_supprimes', [])
        ->assertJsonPath('data.dossier', 'supprime')
        ->assertJsonPath('data.blocage', null);
    oublierJetons();

    supprimerCompte($jeton, ['pin' => '9999', 'confirmation' => 'SUPPRIMER'])->assertStatus(422)->assertJsonPath('message', 'PIN incorrect.');
    supprimerCompte($jeton, ['pin' => '1234', 'confirmation' => 'oui'])->assertStatus(422)->assertJsonValidationErrors('confirmation');
    expect(Personne::find($personne->id))->not->toBeNull();

    supprimerCompte($jeton)->assertOk()->assertJsonPath('message', 'Votre compte Ngoni Santé est supprimé.');

    expect(Personne::withTrashed()->find($personne->id))->toBeNull()
        ->and(TelephonePush::count())->toBe(0)
        ->and(Dossier::count())->toBe(0);
    // Le jeton ne sert plus à rien.
    $this->withToken($jeton)->getJson('/api/compte')->assertUnauthorized();
    oublierJetons();
    // Le même numéro peut ouvrir un nouveau compte.
    inscrireAvantSuppression('76123456', pro: false);
});

it('supprime l’établissement dont il est le seul administrateur, avec ses données ; les reçus payés sont gardés à part', function () {
    $jeton = inscrireAvantSuppression('76123456');
    $cabinet = etablissementDe('76123456');
    // Une infirmière du cabinet : elle part avec lui, sa personne reste.
    User::factory()->create(['etablissement_id' => $cabinet->id, 'telephone' => '76000001', 'role' => 'infirmier']);
    Dossier::create(['etablissement_id' => $cabinet->id, 'nom' => 'Moussa Diarra']);
    // Un abonnement payé sur l'App Store, en cours, et son reçu.
    Abonnement::where('etablissement_id', $cabinet->id)->update(['formule' => 'pro', 'fin' => now()->addMonth()->toDateString(), 'apple_transaction_originale' => '2000000000000001']);
    DemandeAbonnement::create([
        'etablissement_id' => $cabinet->id, 'formule' => 'pro', 'mois' => 1, 'montant' => 15000, 'devise' => 'XOF',
        'moyen' => 'apple_app_store', 'statut' => 'approuvee', 'recu_numero' => 'A26-0001',
    ]);

    $this->withToken($jeton)->getJson('/api/compte/suppression')->assertOk()
        ->assertJsonPath('data.etablissements_supprimes.0.nom', $cabinet->nom)
        ->assertJsonPath('data.etablissements_supprimes.0.patients', 1)
        ->assertJsonPath('data.etablissements_supprimes.0.equipe', 2)
        ->assertJsonPath('data.etablissements_gardes', [])
        ->assertJsonPath('data.abonnement_apple', true);
    oublierJetons();

    supprimerCompte($jeton)->assertOk();

    expect(Etablissement::withTrashed()->find($cabinet->id))->toBeNull()
        ->and(User::withTrashed()->where('etablissement_id', $cabinet->id)->count())->toBe(0)
        ->and(Dossier::where('nom', 'Moussa Diarra')->count())->toBe(1, 'le dossier reste, sans créateur')
        ->and(DemandeAbonnement::count())->toBe(0)
        ->and(Personne::where('telephone', '+22376000001')->exists())->toBeTrue();
    $copie = Storage::disk('local')->files('suppressions');
    expect($copie)->toHaveCount(1)
        ->and(Storage::disk('local')->get($copie[0]))->toContain('A26-0001')->toContain($cabinet->nom);
});

it('laisse à un autre administrateur l’établissement qui continue ; son nom reste sur ses actes, pas son numéro', function () {
    $jeton = inscrireAvantSuppression('76123456');
    $clinique = etablissementDe('76123456');
    $autreAdmin = User::factory()->create(['etablissement_id' => $clinique->id, 'telephone' => '76000002', 'role' => 'admin_etablissement']);
    $membre = User::where('etablissement_id', $clinique->id)->where('telephone', '76123456')->sole();
    $membre->update(['name' => 'Dr Awa Traoré']);
    // Médecin aussi dans un centre qui n'est pas à elle.
    $centre = etablissementDe('76123456', role: 'medecin', proprietaire: false);

    $this->withToken($jeton)->getJson('/api/compte/suppression')->assertOk()
        ->assertJsonPath('data.etablissements_supprimes', [])
        ->assertJsonPath('data.etablissements_gardes.0.nom', $clinique->nom)
        ->assertJsonPath('data.etablissements_gardes.0.repreneur', $autreAdmin->name);
    oublierJetons();

    supprimerCompte($jeton)->assertOk();

    expect(Etablissement::find($clinique->id)?->proprietaire_id)->toBe($autreAdmin->personne_id)
        ->and(Etablissement::find($centre->id))->not->toBeNull()
        ->and(User::find($membre->id))->toBeNull('son accès est fermé');
    $ancien = User::withTrashed()->find($membre->id);
    expect($ancien->name)->toBe('Dr Awa Traoré')
        ->and($ancien->telephone)->toBe('supprime-'.$membre->id)
        ->and($ancien->personne_id)->toBeNull()
        ->and($ancien->actif)->toBeFalse()
        ->and(User::where('etablissement_id', $centre->id)->count())->toBe(0);
});

it('garde chez les établissements le dossier qu’ils ont tenu, sans lien avec le compte, et retire les accès donnés', function () {
    $jeton = inscrireAvantSuppression('76123456', pro: false);
    $personne = Personne::where('telephone', '+22376123456')->sole();
    $centre = Etablissement::factory()->create();
    $dossier = Dossier::create(['personne_id' => $personne->id, 'nom' => 'Awa Traoré']);
    Visite::create(['etablissement_id' => $centre->id, 'dossier_id' => $dossier->id, 'devise' => 'XOF', 'ouverte_at' => now()]);
    $acces = AccesDossier::create(['dossier_id' => $dossier->id, 'etablissement_id' => Etablissement::factory()->create()->id, 'par' => 'qr', 'expire_at' => now()->addDays(7)]);

    $this->withToken($jeton)->getJson('/api/compte/suppression')->assertOk()->assertJsonPath('data.dossier', 'garde');
    oublierJetons();
    supprimerCompte($jeton)->assertOk();

    expect(Dossier::find($dossier->id))->not->toBeNull()
        ->and(Dossier::find($dossier->id)->personne_id)->toBeNull()
        ->and(Visite::where('dossier_id', $dossier->id)->count())->toBe(1)
        ->and(AccesDossier::find($acces->id)->revoque_at)->not->toBeNull();
});

it('ne supprime jamais un établissement de l’éditeur, ni le compte de l’équipe Ngoni Santé', function () {
    $jeton = inscrireAvantSuppression('76123456');
    $demo = etablissementDe('76123456');
    $demo->forceFill(['interne' => true])->save();

    $this->withToken($jeton)->getJson('/api/compte/suppression')->assertOk()
        ->assertJsonPath('data.etablissements_supprimes', [])
        ->assertJsonPath('data.etablissements_gardes.0.repreneur', null);
    oublierJetons();
    supprimerCompte($jeton)->assertOk();
    expect(Etablissement::find($demo->id))->not->toBeNull();

    $equipe = inscrireAvantSuppression('73136789');
    Personne::where('telephone', '+22373136789')->update(['admin_plateforme' => true]);
    supprimerCompte($equipe)->assertStatus(422)
        ->assertJsonPath('message', 'Compte de l’équipe Ngoni Santé : il ne se supprime pas depuis l’application.');
    expect(Personne::where('telephone', '+22373136789')->exists())->toBeTrue();
});
