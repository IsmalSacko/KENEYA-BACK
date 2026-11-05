<?php

declare(strict_types=1);

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Models\User;
use App\Sante\Abonnements\Abonnements;
use App\Sante\Rapports\PeriodeSuivi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

it('donne les comptes page par page avec le total (contrat v1.2)', function () {
    Etablissement::factory()->count(5)->create();

    $this->getJson('/api/console/v1/comptes?page=1&par_page=2', console())->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta', ['total' => 5, 'page' => 1, 'par_page' => 2, 'pages' => 3]);
    $this->getJson('/api/console/v1/comptes?page=3&par_page=2', console())->assertOk()->assertJsonCount(1, 'data');
    // Sans page : la liste d'avant, sans meta.
    $this->getJson('/api/console/v1/comptes', console())->assertOk()->assertJsonCount(5, 'data')->assertJsonMissingPath('meta');
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

    expect($app['capacites'])->toBe(['preuve', 'revoquer', 'approuver_a_vie', 'recettes', 'historique', 'prolonger_essai', 'suivi'])
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
        'champs' => ['modules' => ['file', 'labo'], 'fonctions' => ['rapport'], 'max_etablissements' => 4],
        'tarifs' => [['mois' => 6, 'montant' => null], ['mois' => 1, 'montant' => 14000]],
    ], console())->assertOk()->json('data'))->firstWhere('code', 'pro');
    expect(collect($pro['champs'])->pluck('valeur', 'cle')->all())->toBe(['max_etablissements' => 4, 'modules' => ['file', 'labo'], 'fonctions' => ['rapport']])
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

it('prolonge l’essai : il reste un essai, sans reçu ni recette, la demande attend toujours', function () {
    $e = Etablissement::factory()->create();
    $d = demandeEnAttente($e);
    $fin = today()->addDays(30)->toDateString();

    expect($this->getJson('/api/console/v1/resume', console())->json('application.capacites'))->toContain('prolonger_essai')
        ->and($this->getJson("/api/console/v1/comptes/{$e->uuid}", console())->json('compte.essai_prolongeable'))->toBeTrue();
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/essai", ['fin' => $fin, 'note' => 'Attend son financement'], console())->assertOk()
        ->assertJsonPath('compte.formule', 'essai')->assertJsonPath('compte.fin', $fin)->assertJsonPath('compte.en_cours', true);

    $etat = app(Abonnements::class)->etat($e);
    expect($etat['essai'])->toBeTrue()->and($etat['en_cours'])->toBeTrue()->and($etat['fin'])->toBe($fin)
        ->and($d->fresh()->statut)->toBe('en_attente')->and(DemandeAbonnement::where('statut', 'approuvee')->count())->toBe(0);
    $ligne = collect($this->getJson("/api/console/v1/comptes/{$e->uuid}", console())->json('historique'))->firstWhere('par', 'Console · Ismaila');
    expect($ligne)->type->toBe('essai')->fin->toBe($fin)->par->toBe('Console · Ismaila')->recu_numero->toBeNull()
        ->and($ligne['note'])->toBe('Essai prolongé jusqu’au '.today()->addDays(30)->format('d/m/Y').' · Attend son financement');
});

it('prolonge un essai fini, mais pas une formule payante ni dans le passé', function () {
    $e = Etablissement::factory()->create();
    Abonnement::where('etablissement_id', $e->id)->update(['fin' => today()->subDays(5)->toDateString()]);

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/essai", ['fin' => today()->subDay()->toDateString()], console())->assertUnprocessable();
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/essai", ['fin' => today()->addDays(7)->toDateString()], console())->assertOk()
        ->assertJsonPath('compte.en_cours', true);
    expect(app(Abonnements::class)->etat($e)['essai'])->toBeTrue();

    $this->postJson("/api/console/v1/comptes/{$e->uuid}/abonnement", ['formule' => 'pro', 'fin' => today()->addMonth()->toDateString()], console())->assertOk();
    expect($this->getJson("/api/console/v1/comptes/{$e->uuid}", console())->json('compte.essai_prolongeable'))->toBeFalse();
    $this->postJson("/api/console/v1/comptes/{$e->uuid}/essai", ['fin' => today()->addDays(7)->toDateString()], console())->assertUnprocessable();
    expect(Abonnement::where('etablissement_id', $e->id)->value('formule'))->toBe('pro');
});

// ── Contrat v1.5 et v1.6 : le suivi de l'activité d'un compte ──

/** Une ligne en base, avec son uuid et ses dates. */
function ligneSuivi(string $table, array $champs, string $le): int
{
    return DB::table($table)->insertGetId(['uuid' => (string) Str::uuid(), 'created_at' => $le, 'updated_at' => $le, ...$champs]);
}

/**
 * Une clinique (tous les modules) le jeudi 5 novembre 2026 à 15 h UTC :
 * Awa (déjà venue en septembre) et Bakary (nouveau), une vente au comptoir,
 * un paiement remboursé, une facture annulée, des rendez-vous, une hospitalisation.
 */
function cliniqueSuivie(): Etablissement
{
    test()->travelTo(CarbonImmutable::parse('2026-11-05 15:00:00', 'UTC'));
    $e = Etablissement::factory()->create(['profil' => 'clinique', 'type' => null, 'modules' => null, 'created_at' => '2026-09-01 08:00:00']);
    $dossier = fn (string $nom): int => ligneSuivi('dossiers', ['nom' => $nom, 'etablissement_id' => $e->id], '2026-09-01 08:00:00');
    [$awa, $bakary] = [$dossier('Awa'), $dossier('Bakary')];
    $visite = fn (?int $dossier, string $le, array $en_plus = []): int => ligneSuivi('visites', [
        'etablissement_id' => $e->id, 'dossier_id' => $dossier, 'devise' => 'XOF', 'statut' => 'cloturee', 'ouverte_at' => $le, ...$en_plus,
    ], $le);
    $ligne = fn (int $visite, string $libelle, int $montant, string $le, int $quantite = 1, ?int $medicament = null): int => ligneSuivi('visite_lignes', [
        'visite_id' => $visite, 'libelle' => $libelle, 'quantite' => $quantite, 'prix_unitaire' => intdiv($montant, $quantite), 'montant' => $montant, 'medicament_id' => $medicament,
    ], $le);
    $paiement = fn (int $visite, int $montant, string $le, ?string $rembourse = null): int => ligneSuivi('encaissements', [
        'visite_id' => $visite, 'montant' => $montant, 'moyen' => 'especes', 'rembourse_at' => $rembourse,
    ], $le);

    // Septembre (la période d'avant les 30 derniers jours) : Awa vient une première fois.
    $v = $visite($awa, '2026-09-15 08:00:00');
    $ligne($v, 'Consultation générale', 2000, '2026-09-15 08:00:00');
    $paiement($v, 2000, '2026-09-15 08:10:00');
    // 20 octobre : Bakary, et un paiement saisi par erreur puis remboursé.
    $v = $visite($bakary, '2026-10-20 10:00:00');
    $ligne($v, 'Consultation générale', 2000, '2026-10-20 10:00:00');
    $paiement($v, 2000, '2026-10-20 10:10:00');
    $paiement($v, 500, '2026-10-20 10:12:00', '2026-10-20 10:15:00');
    // 1er novembre : une facture ouverte par erreur, annulée.
    $v = $visite(null, '2026-11-01 09:00:00', ['statut' => 'annulee']);
    $ligne($v, 'Radio', 5000, '2026-11-01 09:00:00');
    // 4 novembre : une vente au comptoir.
    $medicament = DB::table('medicaments')->insertGetId(['etablissement_id' => $e->id, 'nom' => 'Paracétamol 500', 'prix_unitaire' => 500, 'created_at' => now(), 'updated_at' => now()]);
    $v = $visite(null, '2026-11-04 12:00:00', ['nature' => 'vente', 'total' => 1500, 'paye' => 1500]);
    $ligne($v, 'Paracétamol 500', 1500, '2026-11-04 12:00:00', 3, $medicament);
    $paiement($v, 1500, '2026-11-04 12:01:00');
    // Aujourd'hui : Awa revient, consultation et pansement.
    $v = $visite($awa, '2026-11-05 09:00:00');
    $ligne($v, 'Consultation générale', 2000, '2026-11-05 09:00:00');
    $ligne($v, 'Pansement', 1000, '2026-11-05 09:05:00');
    ligneSuivi('examens_cliniques', ['dossier_id' => $awa, 'visite_id' => $v, 'etablissement_id' => $e->id, 'motif' => 'Fièvre'], '2026-11-05 09:20:00');
    $paiement($v, 3000, '2026-11-05 09:30:00');

    foreach ([['honore', '2026-11-02 10:00:00'], ['annule', '2026-11-03 10:00:00'], ['confirme', '2026-11-10 10:00:00']] as [$statut, $debut]) {
        ligneSuivi('rendez_vous', [
            'etablissement_id' => $e->id, 'patient_nom' => 'Awa', 'patient_telephone' => '+22370000001',
            'date_souhaitee' => $debut, 'debut' => $debut, 'statut' => $statut,
        ], '2026-10-25 10:00:00');
    }
    foreach (['en_cours', 'sortie'] as $statut) {
        ligneSuivi('hospitalisations', ['etablissement_id' => $e->id, 'dossier_id' => $bakary, 'motif' => 'Paludisme grave', 'statut' => $statut, 'entree_at' => '2026-11-03 10:00:00'], '2026-11-03 10:00:00');
    }

    return $e;
}

function suivi(string $uuid, string $requete = ''): array
{
    return test()->getJson("/api/console/v1/comptes/{$uuid}{$requete}", console())->assertOk()->json('suivi');
}

it('suit l’activité d’une clinique sur les 30 derniers jours, comparée aux 30 d’avant', function () {
    $e = cliniqueSuivie();

    $s = suivi($e->uuid);
    $ind = collect($s['indicateurs'])->keyBy('cle');
    expect($s['periode'])->toBe(['code' => '30j', 'libelle' => '30 derniers jours', 'du' => '2026-10-07', 'au' => '2026-11-05', 'avant_du' => '2026-09-07', 'avant_au' => '2026-10-06'])
        ->and($ind->keys()->all())->toBe(['encaisse', 'visites', 'patients', 'nouveaux_patients', 'rendez_vous', 'ventes_pharmacie', 'hospitalises', 'derniere_activite'])
        // Ni le paiement remboursé, ni la facture annulée.
        ->and($ind['encaisse'])->toBe(['cle' => 'encaisse', 'libelle' => 'Argent encaissé', 'type' => 'montant', 'valeur' => 6500, 'devise' => 'XOF', 'avant' => 2000])
        ->and($ind['visites'])->toMatchArray(['valeur' => 2, 'avant' => 1, 'note' => '1 consultation'])
        ->and($ind['patients'])->toMatchArray(['valeur' => 2, 'avant' => 1])
        // Awa était déjà venue en septembre : seul Bakary est nouveau.
        ->and($ind['nouveaux_patients'])->toMatchArray(['valeur' => 1, 'avant' => 1])
        ->and($ind['rendez_vous'])->toMatchArray(['valeur' => 1, 'avant' => 0, 'note' => '1 patient venu'])
        ->and($ind['ventes_pharmacie'])->toMatchArray(['type' => 'montant', 'valeur' => 1500, 'avant' => 0, 'devise' => 'XOF', 'note' => '1 vente'])
        ->and($ind['hospitalises'])->toMatchArray(['valeur' => 1])->not->toHaveKey('avant')
        ->and($ind['derniere_activite'])->toMatchArray(['type' => 'instant', 'valeur' => '2026-11-05T09:30:00+00:00', 'note' => 'Encaissement']);

    expect($s['courbe']['titre'])->toBe('Argent encaissé')
        ->and($s['courbe']['points'])->toHaveCount(30)
        ->and($s['courbe']['points'][0])->toBe(['date' => '2026-10-07', 'libelle' => '07/10', 'valeur' => 0, 'ventes' => 0])
        ->and($s['courbe']['points'][29])->toBe(['date' => '2026-11-05', 'libelle' => '05/11', 'valeur' => 3000, 'ventes' => 1])
        ->and(collect($s['courbe']['points'])->sum('valeur'))->toBe(6500);

    $tableaux = collect($s['tableaux'])->keyBy('titre');
    expect($tableaux->keys()->all())->toBe(['Actes les plus faits', 'Produits les plus vendus'])
        ->and($tableaux['Actes les plus faits']['lignes'])->toBe([['Consultation générale', 2, 4000], ['Pansement', 1, 1000]])
        ->and($tableaux['Produits les plus vendus']['lignes'])->toBe([['Paracétamol 500', 3, 1500]])
        ->and($tableaux['Produits les plus vendus']['colonnes'][2])->toBe(['libelle' => 'Montant', 'type' => 'montant']);
});

it('suit l’activité sur la période choisie : aujourd’hui, 7 jours, ce mois, depuis le début, dates choisies', function () {
    $e = cliniqueSuivie();
    $encaisse = fn (array $s): array => collect($s['indicateurs'])->firstWhere('cle', 'encaisse');

    $jour = suivi($e->uuid, '?periode=jour');
    expect($jour['periode'])->toBe(['code' => 'jour', 'libelle' => 'Aujourd’hui', 'du' => '2026-11-05', 'au' => '2026-11-05', 'avant_du' => '2026-11-04', 'avant_au' => '2026-11-04'])
        ->and($encaisse($jour))->toMatchArray(['valeur' => 3000, 'avant' => 1500])
        ->and($jour['courbe']['points'])->toHaveCount(24)
        ->and($jour['courbe']['points'][0])->toMatchArray(['date' => '2026-11-05T00:00', 'libelle' => '0 h'])
        ->and($jour['courbe']['points'][9])->toBe(['date' => '2026-11-05T09:00', 'libelle' => '9 h', 'valeur' => 3000, 'ventes' => 1])
        ->and(collect($jour['tableaux'])->firstWhere('titre', 'Actes les plus faits')['lignes'])->toBe([['Consultation générale', 1, 2000], ['Pansement', 1, 1000]]);

    $semaine = suivi($e->uuid, '?periode=7j');
    expect($semaine['periode'])->toMatchArray(['libelle' => '7 derniers jours', 'du' => '2026-10-30', 'avant_du' => '2026-10-23', 'avant_au' => '2026-10-29'])
        ->and($encaisse($semaine))->toMatchArray(['valeur' => 4500, 'avant' => 0])
        ->and($semaine['courbe']['points'])->toHaveCount(7);

    $mois = suivi($e->uuid, '?periode=mois');
    expect($mois['periode'])->toBe(['code' => 'mois', 'libelle' => 'Ce mois', 'du' => '2026-11-01', 'au' => '2026-11-05', 'avant_du' => '2026-10-01', 'avant_au' => '2026-10-05'])
        ->and($encaisse($mois))->toMatchArray(['valeur' => 4500, 'avant' => 0])
        ->and($mois['courbe']['points'])->toHaveCount(5);

    // Depuis la première activité (15 septembre), sans comparaison, un point par mois.
    $tout = suivi($e->uuid, '?periode=tout');
    expect($tout['periode'])->toBe(['code' => 'tout', 'libelle' => 'Depuis le début', 'du' => '2026-09-15', 'au' => '2026-11-05', 'avant_du' => null, 'avant_au' => null])
        ->and($encaisse($tout))->toBe(['cle' => 'encaisse', 'libelle' => 'Argent encaissé', 'type' => 'montant', 'valeur' => 8500, 'devise' => 'XOF'])
        ->and(collect($tout['indicateurs'])->filter(fn (array $i): bool => array_key_exists('avant', $i)))->toBeEmpty()
        ->and($tout['courbe']['points'])->toBe([
            ['date' => '2026-09-01', 'libelle' => 'sept. 2026', 'valeur' => 2000, 'ventes' => 1],
            ['date' => '2026-10-01', 'libelle' => 'oct. 2026', 'valeur' => 2000, 'ventes' => 1],
            ['date' => '2026-11-01', 'libelle' => 'nov. 2026', 'valeur' => 4500, 'ventes' => 2],
        ]);

    // Une seule journée : par heure ; 62 jours au plus : par jour ; au-delà : par mois.
    $unJour = suivi($e->uuid, '?periode=perso&du=2026-10-20&au=2026-10-20');
    expect($unJour['periode'])->toBe(['code' => 'perso', 'libelle' => 'Du 20/10/2026 au 20/10/2026', 'du' => '2026-10-20', 'au' => '2026-10-20', 'avant_du' => '2026-10-19', 'avant_au' => '2026-10-19'])
        ->and($unJour['courbe']['points'])->toHaveCount(24)
        ->and($unJour['courbe']['points'][10]['valeur'])->toBe(2000)
        ->and($encaisse($unJour))->toMatchArray(['valeur' => 2000, 'avant' => 0]);
    $deuxMois = suivi($e->uuid, '?periode=perso&du=2026-09-01&au=2026-10-31');
    expect($deuxMois['courbe']['points'])->toHaveCount(61)
        ->and($deuxMois['periode'])->toMatchArray(['avant_du' => '2026-07-02', 'avant_au' => '2026-08-31'])
        ->and($encaisse($deuxMois))->toMatchArray(['valeur' => 4000, 'avant' => 0]);
    $annee = suivi($e->uuid, '?periode=perso&du=2026-01-10&au=2026-11-05');
    expect(collect($annee['courbe']['points'])->pluck('libelle')->all())
        ->toBe(['janv. 2026', 'févr. 2026', 'mars 2026', 'avr. 2026', 'mai 2026', 'juin 2026', 'juil. 2026', 'août 2026', 'sept. 2026', 'oct. 2026', 'nov. 2026'])
        ->and(collect($annee['courbe']['points'])->sum('valeur'))->toBe(8500);
});

it('refuse une période invalide en mots simples', function (string $requete, string $message) {
    $e = Etablissement::factory()->create();
    test()->travelTo(CarbonImmutable::parse('2026-11-05 15:00:00', 'UTC'));

    $this->getJson("/api/console/v1/comptes/{$e->uuid}{$requete}", console())->assertUnprocessable()->assertExactJson(['message' => $message]);
})->with([
    'inconnue' => ['?periode=semaine', 'Période inconnue : choisissez jour, 7j, mois, tout ou perso.'],
    'perso sans dates' => ['?periode=perso', 'Indiquez la date de début et la date de fin de la période (du et au, au format AAAA-MM-JJ).'],
    'date impossible' => ['?periode=perso&du=2026-02-30&au=2026-03-05', 'Indiquez la date de début et la date de fin de la période (du et au, au format AAAA-MM-JJ).'],
    'fin dans le futur' => ['?periode=perso&du=2026-11-01&au=2026-11-06', 'La date de fin ne peut pas être après aujourd’hui.'],
    'début après la fin' => ['?periode=perso&du=2026-10-10&au=2026-10-01', 'La date de début doit être avant la date de fin.'],
    'plus de 3 ans' => ['?periode=perso&du=2023-11-04&au=2026-11-05', 'Choisissez une période de 3 ans au plus.'],
]);

it('borne la comparaison du mois à la fin du mois d’avant, et la courbe de tout à 24 mois', function () {
    $sans = fn (): CarbonImmutable => CarbonImmutable::parse('2020-01-15');

    $mars = PeriodeSuivi::depuis('mois', null, null, CarbonImmutable::parse('2026-03-31 10:00', 'UTC'), $sans);
    expect($mars->pourContrat())->toMatchArray(['du' => '2026-03-01', 'au' => '2026-03-31', 'avant_du' => '2026-02-01', 'avant_au' => '2026-02-28']);

    $tout = PeriodeSuivi::depuis('tout', null, null, CarbonImmutable::parse('2026-11-05 10:00', 'UTC'), $sans);
    expect($tout->du->toDateString())->toBe('2020-01-15')
        ->and($tout->creneaux())->toHaveCount(24)
        ->and($tout->creneaux()[0]['date'])->toBe('2024-12-01')
        ->and($tout->debutCourbe()->toDateString())->toBe('2024-12-01');

    expect(PeriodeSuivi::depuis('perso', '2023-11-05', '2026-11-05', CarbonImmutable::parse('2026-11-05', 'UTC'), $sans)->creneaux())->toHaveCount(37);
});

it('montre à une pharmacie ses ventes, pas des patients ; et à un compte vide, des zéros', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-05 15:00:00', 'UTC'));
    $pharmacie = Etablissement::factory()->create(['profil' => 'pharmacie', 'type' => null, 'modules' => null, 'created_at' => '2026-10-01 08:00:00']);

    $s = suivi($pharmacie->uuid);
    expect(collect($s['indicateurs'])->pluck('cle')->all())->toBe(['encaisse', 'ventes_pharmacie', 'derniere_activite'])
        ->and(collect($s['indicateurs'])->firstWhere('cle', 'derniere_activite')['valeur'])->toBeNull()
        ->and(collect($s['tableaux'])->pluck('titre')->all())->toBe(['Produits les plus vendus'])
        ->and(collect($s['courbe']['points'])->sum('valeur'))->toBe(0);
    // Sans aucune activité, « depuis le début » part de la création du compte.
    expect(suivi($pharmacie->uuid, '?periode=tout')['periode']['du'])->toBe('2026-10-01');
});

it('décrit l’établissement dans les détails : type, pays, création, modules, équipe', function () {
    $e = Etablissement::factory()->create(['profil' => 'cabinet_medical', 'type' => null, 'modules' => null, 'pays_code' => 'ML']);
    User::factory()->create(['etablissement_id' => $e->id, 'role' => 'admin_etablissement']);
    User::factory()->count(2)->create(['etablissement_id' => $e->id, 'role' => 'medecin']);
    User::factory()->create(['etablissement_id' => $e->id, 'role' => 'caissier', 'actif' => false]);

    $details = collect($this->getJson("/api/console/v1/comptes/{$e->uuid}", console())->assertOk()->json('details'))->pluck('valeur', 'libelle');
    expect($details['Type d’établissement'])->toBe('Cabinet médical')
        ->and($details['Pays'])->toBe('Mali')
        ->and($details['Créé le'])->toBe(today()->toDateString())
        ->and($details['Modules ouverts'])->toBe('File d\'attente, Rendez-vous, Consultation, Caisse')
        ->and($details['Équipe'])->toBe('3 membres actifs : Médecin 2, Admin d’établissement 1');
});
