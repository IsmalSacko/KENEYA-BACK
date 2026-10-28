<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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

// ── Contrat v1.1 : tout l'abonnement dans la console ──

function demandeEnAttente(Etablissement $e, array $en_plus = []): DemandeAbonnement
{
    return DemandeAbonnement::create([
        'etablissement_id' => $e->id, 'formule' => 'pro', 'mois' => 3, 'montant' => 42000,
        'devise' => 'XOF', 'moyen' => 'orange_money', 'statut' => 'en_attente', ...$en_plus,
    ]);
}

it('annonce ses capacités et ses moyens de paiement', function () {
    $app = $this->getJson('/api/console/v1/resume', console())->assertOk()->json('application');

    expect($app['capacites'])->toBe(['preuve', 'revoquer', 'approuver_a_vie', 'recettes', 'historique'])
        ->and(collect($app['moyens'])->pluck('libelle', 'code')->all())->toBe([
            'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave',
            'especes' => 'Espèces', 'virement' => 'Virement',
        ]);
});

it('cherche dans les demandes et montre les annulées', function () {
    $banconi = Etablissement::factory()->create(['nom' => 'CSCom de Banconi']);
    $autre = Etablissement::factory()->create(['nom' => 'Clinique du Fleuve']);
    demandeEnAttente($banconi, ['note' => 'ID: MP261028.1234']);
    demandeEnAttente($autre, ['telephone' => '+22376123456']);
    demandeEnAttente($autre, ['statut' => 'annulee']);

    $nom = fn (string $url) => collect($this->getJson($url, console())->assertOk()->json('data'))->pluck('compte.nom')->all();
    expect($nom('/api/console/v1/demandes?q=banconi'))->toBe(['CSCom de Banconi'])
        ->and($nom('/api/console/v1/demandes?q=MP261028'))->toBe(['CSCom de Banconi'])
        ->and($nom('/api/console/v1/demandes?q=76123456'))->toBe(['Clinique du Fleuve'])
        ->and($nom('/api/console/v1/demandes?statut=annulee'))->toBe(['Clinique du Fleuve'])
        ->and($nom('/api/console/v1/demandes?statut=toutes'))->toHaveCount(3);
    $this->getJson('/api/console/v1/demandes?statut=nimporte', console())->assertUnprocessable();
});

it('approuve avec le vrai moyen et la référence, et note qui a décidé', function () {
    $d = demandeEnAttente(Etablissement::factory()->create());

    $r = $this->postJson("/api/console/v1/demandes/{$d->uuid}/approuver", [
        'moyen' => 'wave', 'reference' => 'TX-998877', 'note' => 'Vu sur le relevé',
    ], console())->assertOk()->json('demande');

    expect($r['moyen'])->toBe('wave')
        ->and($r['reference'])->toBe('TX-998877')
        ->and($r['note_decision'])->toBe('Vu sur le relevé')
        ->and($r['par'])->toBe('Console · Ismaila')
        ->and($r['frais'])->toBe(0)
        ->and($r['automatique'])->toBeFalse()
        ->and($r['recu_numero'])->toMatch('/^A\d{2}-\d{4}$/');
    expect($d->fresh())->decide_par_nom->toBe('Console · Ismaila');
    $this->postJson("/api/console/v1/demandes/{$d->uuid}/approuver", ['moyen' => 'cheque'], console())->assertUnprocessable();
});

it('approuve sans échéance', function () {
    $e = Etablissement::factory()->create();
    $d = demandeEnAttente($e);

    $this->postJson("/api/console/v1/demandes/{$d->uuid}/approuver", ['a_vie' => true], console())->assertOk()
        ->assertJsonPath('demande.periode_fin', null);
    expect(Abonnement::where('etablissement_id', $e->id)->first())->formule->toBe('pro')->fin->toBeNull();
});

it('refuse en notant le motif et qui a refusé', function () {
    $d = demandeEnAttente(Etablissement::factory()->create());

    $this->postJson("/api/console/v1/demandes/{$d->uuid}/refuser", ['motif' => 'Paiement introuvable'], console())->assertOk()
        ->assertJsonPath('demande.statut', 'refusee')
        ->assertJsonPath('demande.note_decision', 'Paiement introuvable')
        ->assertJsonPath('demande.par', 'Console · Ismaila');
});

it('accorde avec un paiement : reçu numéroté, fin respectée, demande en attente soldée, recette', function () {
    $e = Etablissement::factory()->create();
    $d = demandeEnAttente($e);

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/abonnement", [
        'formule' => 'pro', 'debut' => '2026-11-01', 'fin' => '2027-01-31',
        'montant' => 40000, 'moyen' => 'especes', 'reference' => 'CAISSE-12', 'note' => 'Payé au bureau',
    ], console())->assertOk();

    $abo = Abonnement::where('etablissement_id', $e->id)->first();
    expect($abo->fin->toDateString())->toBe('2027-01-31')->and($abo->accorde_par_nom)->toBe('Console · Ismaila');
    $d->refresh();
    expect($d->statut)->toBe('approuvee')
        ->and($d->montant)->toBe(40000)
        ->and($d->montant_demande)->toBe(42000)
        ->and($d->moyen)->toBe('especes')
        ->and($d->reference)->toBe('CAISSE-12')
        ->and($d->mois)->toBe(3)
        ->and($d->periode_debut->toDateString())->toBe('2026-11-01')
        ->and($d->periode_fin->toDateString())->toBe('2027-01-31')
        ->and($d->recu_numero)->toMatch('/^A\d{2}-\d{4}$/');

    $accord = collect($this->getJson("/api/console/v1/comptes/{$e->uuid}", console())->assertOk()->json('historique'))
        ->firstWhere('type', 'accord');
    expect($accord)->montant->toBe(40000)->recu_numero->toBe($d->recu_numero)->par->toBe('Console · Ismaila')
        ->reference->toBe('CAISSE-12')->fin->toBe('2027-01-31');
    expect($this->getJson('/api/console/v1/recettes', console())->json('data.0.recu_numero'))->toBe($d->recu_numero);
});

it('accorde avec un paiement sans demande : un reçu à part, hors de la liste des demandes', function () {
    $e = Etablissement::factory()->create();

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/abonnement", ['formule' => 'pro', 'fin' => null, 'montant' => 5000], console())
        ->assertUnprocessable();
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/abonnement", [
        'formule' => 'essentiel', 'fin' => today()->addMonthsNoOverflow(12)->subDay()->toDateString(), 'montant' => 50000, 'moyen' => 'wave',
    ], console())->assertOk();

    $recu = DemandeAbonnement::where('etablissement_id', $e->id)->sole();
    expect($recu)->accord->toBeTrue()->statut->toBe('approuvee')->mois->toBe(12)->recu_numero->not->toBeNull();
    expect($this->getJson('/api/console/v1/demandes?statut=toutes', console())->json('data'))->toBe([]);
    expect($this->getJson('/api/console/v1/recettes', console())->json('data.0'))
        ->montant->toBe(50000)->accord->toBeTrue()->moyen->toBe('wave');
});

it('accorde sans montant : pas de reçu, la demande en attente reste', function () {
    $e = Etablissement::factory()->create();
    $d = demandeEnAttente($e);

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/abonnement", ['formule' => 'pro', 'fin' => today()->addDays(7)->toDateString()], console())
        ->assertOk();

    expect($d->fresh()->statut)->toBe('en_attente')->and(DemandeAbonnement::count())->toBe(1);
});

it('révoque : l’abonnement s’arrête maintenant, au journal', function () {
    $e = Etablissement::factory()->create();

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/revoquer", [], console())->assertUnprocessable();
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/revoquer", ['motif' => 'Fraude'], console())->assertOk();

    $abo = Abonnement::where('etablissement_id', $e->id)->first();
    expect($abo->enCours())->toBeFalse()->and($abo->note)->toBe('Fraude');
    $this->getJson("/api/console/v1/comptes/{$e->uuid}", console())
        ->assertJsonPath('compte.en_cours', false)
        ->assertJsonPath('historique.0.type', 'revocation')
        ->assertJsonPath('historique.0.par', 'Console · Ismaila')
        ->assertJsonPath('historique.0.note', 'Fraude');
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/revoquer", ['motif' => 'Encore'], console())->assertUnprocessable();
});

it('montre la fiche d’un compte : autorisation, détails, historique depuis l’essai', function () {
    $e = Etablissement::factory()->create(['autorisation' => 'AUT-2026-123', 'responsable' => 'Dr Diarra']);
    $d = demandeEnAttente($e);
    $this->postJson("/api/console/v1/demandes/{$d->uuid}/approuver", ['reference' => 'MP1'], console())->assertOk();

    expect($this->getJson('/api/console/v1/comptes', console())->json('data.0.autorisation'))->toBe('AUT-2026-123');
    $fiche = $this->getJson("/api/console/v1/comptes/{$e->uuid}", console())->assertOk()->json();
    expect($fiche['compte']['id'])->toBe($e->uuid)
        ->and(collect($fiche['details'])->pluck('valeur', 'libelle')->all())->toMatchArray([
            'N° d’autorisation' => 'AUT-2026-123', 'Responsable' => 'Dr Diarra',
        ])
        ->and(collect($fiche['historique'])->pluck('type')->sort()->values()->all())->toBe(['essai', 'paiement']);
    $paiement = collect($fiche['historique'])->firstWhere('type', 'paiement');
    expect($paiement)->reference->toBe('MP1')->par->toBe('Console · Ismaila')->recu_numero->not->toBeNull();
    $this->getJson('/api/console/v1/comptes/00000000-0000-4000-8000-000000000000', console())->assertNotFound();
});

it('règle les modules, les fonctions et les durées d’une formule', function () {
    $pro = collect($this->getJson('/api/console/v1/formules', console())->assertOk()->json('data'))->firstWhere('code', 'pro');
    $champs = collect($pro['champs'])->keyBy('cle');
    expect($champs['modules']['type'])->toBe('choix_multiple')
        ->and(collect($champs['modules']['options'])->pluck('libelle', 'valeur')['labo'])->toBe('Laboratoire')
        ->and(collect($champs['fonctions']['options'])->pluck('libelle', 'valeur')->all())
        ->toBe(['ecrans' => 'Écran de salle et borne', 'rapport' => 'Rapport du mois'])
        ->and($champs['fonctions']['valeur'])->toBe(['ecrans', 'rapport']);

    $pro = collect($this->patchJson('/api/console/v1/formules/pro', [
        'champs' => ['modules' => ['file', 'labo'], 'fonctions' => ['rapport']],
        'tarifs' => [['mois' => 6, 'montant' => null], ['mois' => 1, 'montant' => 14000]],
    ], console())->assertOk()->json('data'))->firstWhere('code', 'pro');
    expect(collect($pro['champs'])->pluck('valeur', 'cle')->all())->toBe(['modules' => ['file', 'labo'], 'fonctions' => ['rapport']])
        ->and(collect($pro['tarifs'])->pluck('montant', 'mois')->all())->toBe([1 => 14000, 3 => 42000, 12 => 150000]);

    $this->patchJson('/api/console/v1/formules/pro', ['fonctions' => ['ecrans']], console())->assertOk();
    expect(Formule::where('code', 'pro')->first()->fonctions)->toBe(['ecrans']);
    $this->patchJson('/api/console/v1/formules/pro', ['champs' => ['fonctions' => ['tout']]], console())->assertUnprocessable();
    $this->patchJson('/api/console/v1/formules/pro', ['champs' => ['inconnu' => 1]], console())->assertUnprocessable();
});

it('relaie la preuve d’une demande, 404 sans preuve', function () {
    Storage::fake();
    $e = Etablissement::factory()->create();
    $sans = demandeEnAttente($e);
    $this->get("/api/console/v1/demandes/{$sans->uuid}/preuve", console())->assertNotFound();

    Storage::put('preuves/recu.png', 'image');
    $avec = demandeEnAttente(Etablissement::factory()->create(), ['preuve_chemin' => 'preuves/recu.png']);
    expect($this->getJson('/api/console/v1/demandes', console())->json('data'))->toHaveCount(2);
    $this->get("/api/console/v1/demandes/{$avec->uuid}/preuve", console())->assertOk()->assertHeader('content-type', 'image/png');
});

it('liste les recettes entre deux dates, sans les établissements de l’éditeur', function () {
    $client = Etablissement::factory()->create();
    $demo = Etablissement::factory()->create(['interne' => true]);
    demandeEnAttente($client, ['statut' => 'approuvee', 'decide_at' => '2026-09-15 10:00:00', 'montant' => 15000]);
    demandeEnAttente($client, ['statut' => 'approuvee', 'decide_at' => '2026-10-05 10:00:00', 'reference' => 'W1']);
    demandeEnAttente($demo, ['statut' => 'approuvee', 'decide_at' => '2026-10-06 10:00:00']);
    demandeEnAttente($client, ['statut' => 'refusee', 'decide_at' => '2026-10-07 10:00:00']);

    $r = $this->getJson('/api/console/v1/recettes?du=2026-10-01&au=2026-10-31', console())->assertOk()->json();
    expect($r['data'])->toHaveCount(1)
        ->and($r['data'][0])->toMatchArray(['montant' => 42000, 'reference' => 'W1', 'frais' => 0, 'automatique' => false])
        ->and($r['totaux'])->toBe([['devise' => 'XOF', 'total' => 42000, 'frais' => 0]]);
    expect($this->getJson('/api/console/v1/recettes?du=2026-09-01&au=2026-10-31', console())->json('totaux.0.total'))->toBe(57000);
});
