<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Lot;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Sante\File\Statut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/** Une pharmacie (admin connecté), un produit vendu au comprimé, deux lots. */
function pharmacie(string $profil = 'pharmacie'): array
{
    $etab = Etablissement::factory()->create(['profil' => $profil, 'type' => null, 'modules' => null]);
    $admin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'admin_etablissement']);
    test()->actingAs($admin, 'api');
    $produit = test()->postJson('/api/pharmacie/produits', [
        'nom' => 'Paracétamol 500 mg', 'dci' => 'Paracétamol', 'forme' => 'Comprimé', 'dosage' => '500 mg',
        'conditionnement' => 30, 'vente_unite' => true, 'prix_unitaire' => 1500, 'prix_unite' => 60, 'seuil_alerte' => 40,
    ])->assertStatus(201)->json('produit.uuid');
    test()->postJson('/api/pharmacie/receptions', ['lignes' => [
        ['medicament_uuid' => $produit, 'numero' => 'A', 'peremption' => '2027-06-30', 'boites' => 2],
        ['medicament_uuid' => $produit, 'numero' => 'B', 'peremption' => '2026-12-31', 'boites' => 1],
    ]])->assertStatus(201);

    return [$etab, $admin, $produit];
}

function lots(string $produit): array
{
    return Lot::whereIn('medicament_id', Medicament::where('uuid', $produit)->pluck('id'))
        ->orderBy('numero')->pluck('quantite', 'numero')->all();
}

it('reçoit les lots en boîtes et compte le stock en unités', function () {
    [, , $produit] = pharmacie();

    $p = $this->getJson('/api/pharmacie/produits')->json('data.0');
    expect($p['stock_unites'])->toBe(90)->and($p['stock_boites'])->toBe(3)
        ->and(collect($p['lots'])->pluck('numero')->all())->toBe(['B', 'A']); // péremption la plus proche d'abord
});

it('délivre d’abord le lot qui périme le plus tôt, et facture sur la visite', function () {
    [, , $produit] = pharmacie();

    $visite = $this->postJson('/api/pharmacie/delivrances', ['lignes' => [
        ['medicament_uuid' => $produit, 'quantite' => 40, 'unite' => 'unite'],
    ]])->assertOk()->json('visite');

    expect(lots($produit))->toBe(['A' => 50, 'B' => 0])
        ->and($visite['total'])->toBe(40 * 60)
        ->and($visite['lignes'][0]['libelle'])->toBe('Paracétamol 500 mg · 40 unité(s)');

    $boites = $this->postJson('/api/pharmacie/delivrances', ['lignes' => [
        ['medicament_uuid' => $produit, 'quantite' => 1, 'unite' => 'boite'],
    ]])->json('visite');
    expect($boites['total'])->toBe(1500)->and(lots($produit)['A'])->toBe(20);
});

it('ne délivre jamais un lot périmé', function () {
    [, , $produit] = pharmacie();
    Carbon::setTestNow(Carbon::parse('2027-01-15 09:00:00', 'UTC')); // le lot B (fin 2026) est périmé

    $this->postJson('/api/pharmacie/delivrances', ['lignes' => [
        ['medicament_uuid' => $produit, 'quantite' => 61, 'unite' => 'unite'],
    ]])->assertStatus(422);
    $this->postJson('/api/pharmacie/delivrances', ['lignes' => [
        ['medicament_uuid' => $produit, 'quantite' => 60, 'unite' => 'unite'],
    ]])->assertOk();
    expect(lots($produit))->toBe(['A' => 0, 'B' => 30]);

    $alertes = $this->getJson('/api/pharmacie/alertes')->json();
    expect($alertes['perimes'][0]['lot'])->toBe('B')->and($alertes['stock_bas'][0]['stock'])->toBe(30);
});

it("rattrape une vente faite par l'app 3.x (stock baissé sans lot)", function () {
    [, , $produit] = pharmacie();
    Medicament::where('uuid', $produit)->update(['stock' => 80]); // la synchro a retiré 10 unités

    $this->getJson('/api/pharmacie/produits')->assertOk();
    expect(lots($produit))->toBe(['A' => 60, 'B' => 20]);
});

it('refuse un produit sur ordonnance sans ordonnance', function () {
    [, , $produit] = pharmacie();
    $this->patchJson("/api/pharmacie/produits/{$produit}", ['ordonnance_requise' => true])->assertOk();

    $this->postJson('/api/pharmacie/delivrances', ['lignes' => [
        ['medicament_uuid' => $produit, 'quantite' => 1, 'unite' => 'boite'],
    ]])->assertStatus(422);
});

it("délivre une ordonnance d'un autre établissement, jamais au-delà de ce qui est prescrit", function () {
    // Le médecin du centre de santé prescrit.
    $cscom = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $medecin = User::factory()->create(['etablissement_id' => $cscom->id, 'role' => 'medecin', 'name' => 'Dr Diarra']);
    $ordonnance = $this->actingAs($medecin, 'api')->postJson('/api/ordonnances', [
        'patient_nom' => 'Awa Traoré',
        'lignes' => [['produit' => 'Paracétamol 500 mg', 'posologie' => '1 cp 3 fois par jour', 'quantite' => 18]],
    ])->assertStatus(201)->json('ordonnance');
    expect($ordonnance['qr'])->toStartWith('ngoni-sante:ord:');

    // La pharmacie du quartier scanne le QR.
    [, , $produit] = pharmacie();
    $lue = $this->postJson('/api/pharmacie/ordonnances/lire', ['qr' => $ordonnance['qr']])->assertOk()->json();
    expect($lue['authentique'])->toBeTrue()
        ->and($lue['ordonnance']['prescripteur'])->toBe('Dr Diarra')
        ->and($lue['ordonnance']['lignes'][0]['reste'])->toBe(18);

    $ligne = $lue['ordonnance']['lignes'][0]['uuid'];
    $delivrer = fn (int $q) => $this->postJson('/api/pharmacie/delivrances', [
        'ordonnance_uuid' => $ordonnance['uuid'],
        'lignes' => [['medicament_uuid' => $produit, 'quantite' => $q, 'unite' => 'unite', 'ordonnance_ligne_uuid' => $ligne]],
    ]);

    $delivrer(10)->assertOk()->assertJsonPath('ordonnance.statut', 'partielle');
    $delivrer(10)->assertStatus(422); // il n'en reste que 8
    $delivrer(8)->assertOk()->assertJsonPath('ordonnance.statut', 'delivree');
});

it('rejette un QR d’ordonnance modifié', function () {
    $cscom = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    $medecin = User::factory()->create(['etablissement_id' => $cscom->id, 'role' => 'medecin']);
    $qr = $this->actingAs($medecin, 'api')->postJson('/api/ordonnances', [
        'patient_nom' => 'Awa', 'lignes' => [['produit' => 'Amoxicilline', 'quantite' => 12]],
    ])->json('ordonnance.qr');

    // Quelqu'un remplace 12 par 120 dans le contenu.
    [$donnees, $signature] = explode('.', substr($qr, strlen('ngoni-sante:ord:')));
    $json = str_replace('12', '120', base64_decode(strtr($donnees, '-_', '+/')));
    $falsifie = 'ngoni-sante:ord:'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.$signature;

    pharmacie();
    $this->postJson('/api/pharmacie/ordonnances/lire', ['qr' => $falsifie])
        ->assertStatus(422)->assertJsonPath('code', 'signature_invalide');
});

it('publie la clé qui vérifie les ordonnances hors ligne', function () {
    $cle = $this->getJson('/api/public/ordonnances/cle')->assertOk()->json('cle');
    expect(strlen(base64_decode(strtr($cle, '-_', '+/'))))->toBe(32);
});

it('garde le nombre de boîtes quand on change le conditionnement', function () {
    [, , $produit] = pharmacie();
    $this->patchJson("/api/pharmacie/produits/{$produit}", ['conditionnement' => 10])->assertOk()
        ->assertJsonPath('produit.stock_boites', 3)->assertJsonPath('produit.stock_unites', 30);
});

it('ajuste un lot à l’inventaire et garde la trace', function () {
    [, , $produit] = pharmacie();
    $lot = Lot::where('numero', 'A')->value('uuid');

    $this->postJson("/api/pharmacie/lots/{$lot}/inventaire", ['quantite' => 55])->assertOk();

    expect(Medicament::where('uuid', $produit)->value('stock'))->toBe(85)
        ->and(MouvementStock::where('type', 'ajustement')->value('quantite'))->toBe(5);
});

it('reprend le stock existant en un lot « REPRISE »', function () {
    $etab = Etablissement::factory()->create();
    $id = DB::table('medicaments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'etablissement_id' => $etab->id, 'nom' => 'Ancien', 'stock' => 12,
        'prix_unitaire' => 500, 'seuil_alerte' => 5, 'date_expiration' => '2027-03-01', 'actif' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_27_160000_create_pharmacie_tables.php');
    (fn () => $this->reprendreStocks())->call($migration);

    $lot = Lot::where('medicament_id', $id)->sole();
    expect($lot->numero)->toBe('REPRISE')->and($lot->quantite)->toBe(12)->and($lot->peremption?->toDateString())->toBe('2027-03-01');
});

it('refuse deux produits du même nom', function () {
    pharmacie();
    $this->postJson('/api/pharmacie/produits', ['nom' => 'Amoxicilline 500 mg', 'conditionnement' => 12, 'prix_unitaire' => 1500])->assertStatus(201);
    $this->postJson('/api/pharmacie/produits', ['nom' => '  amoxicilline 500 MG ', 'conditionnement' => 12, 'prix_unitaire' => 1500])
        ->assertStatus(422)->assertJsonPath('message', 'Ce produit existe déjà : modifiez-le, ou faites une réception.');
});

it('montre à la pharmacie qui attend et ses ordonnances, puis le sort de la file une fois servi', function () {
    [$etab, $admin, $produit] = pharmacie('centre_sante');
    $this->getJson('/api/file/services');
    $pharma = Service::where('etablissement_id', $etab->id)->where('nom', 'Pharmacie')->value('uuid');
    $consult = Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid');
    $t = $this->postJson('/api/file/tickets', ['service_uuid' => $consult, 'nom' => 'Mariam Sidibé'])->json('ticket');

    // Le médecin voit les produits (prix, stock) et prescrit dans la liste.
    $medecin = User::factory()->create(['etablissement_id' => $etab->id, 'role' => 'medecin']);
    auth()->forgetGuards();
    $this->actingAs($medecin, 'api');
    expect($this->getJson('/api/pharmacie/produits')->assertOk()->json('data.0.uuid'))->toBe($produit);
    $this->postJson('/api/ordonnances', ['visite_uuid' => $t['visite_uuid'], 'lignes' => [
        ['produit' => 'Paracétamol 500 mg', 'medicament_uuid' => $produit, 'posologie' => '1 cp x 3', 'quantite' => 9],
    ]])->assertStatus(201);
    // Il l'envoie à la pharmacie, puis la pharmacie prend la main.
    $ticketPharma = $this->postJson("/api/file/tickets/{$t['uuid']}/transferer", ['service_uuid' => $pharma])->assertOk()->json('ticket');
    auth()->forgetGuards();
    $this->actingAs($admin, 'api');

    $servir = $this->getJson('/api/pharmacie/a-servir')->assertOk()->json('data');
    expect($servir)->toHaveCount(1)
        ->and($servir[0]['patient'])->toBe('Mariam Sidibé')
        ->and($servir[0]['ticket']['code'])->toBe($ticketPharma['code'])
        ->and($servir[0]['ordonnances'][0]['lignes'][0]['medicament_uuid'])->toBe($produit);

    $this->postJson('/api/pharmacie/delivrances', [
        'ordonnance_uuid' => $servir[0]['ordonnances'][0]['uuid'],
        'lignes' => [['medicament_uuid' => $produit, 'quantite' => 9, 'unite' => 'unite']],
    ])->assertOk()->assertJsonPath('visite.uuid', $t['visite_uuid']);
    expect($this->getJson('/api/pharmacie/a-servir')->json('data'))->toBe([])
        ->and(Ticket::where('uuid', $ticketPharma['uuid'])->value('statut'))->toBe(Statut::Termine);
});

it('sert quand même un patient dont la caisse a clôturé la visite, sur une nouvelle facture', function () {
    [$etab, $admin, $produit] = pharmacie('centre_sante');
    $this->getJson('/api/file/services');
    $consult = Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid');
    $t = $this->postJson('/api/file/tickets', ['service_uuid' => $consult, 'nom' => 'Ousmane Diaby'])->json('ticket');
    $o = $this->postJson('/api/ordonnances', ['visite_uuid' => $t['visite_uuid'], 'lignes' => [
        ['produit' => 'Paracétamol', 'medicament_uuid' => $produit, 'posologie' => '1 cp matin et soir', 'quantite' => 2],
    ]])->assertStatus(201)->json('ordonnance');

    // La caisse voit l'ordonnance pas encore servie, puis clôture quand même.
    $this->getJson("/api/visites/{$t['visite_uuid']}")->assertJsonPath('visite.ordonnances_a_servir', 1);
    $this->postJson("/api/visites/{$t['visite_uuid']}/cloturer")->assertOk();
    expect($this->getJson('/api/pharmacie/a-servir')->json('data'))->toBe([]);

    // Il revient à la pharmacie avec le QR : servi, sur une nouvelle facture.
    $nouvelle = $this->postJson('/api/pharmacie/delivrances', [
        'ordonnance_uuid' => $o['uuid'], 'visite_uuid' => $t['visite_uuid'],
        'lignes' => [['medicament_uuid' => $produit, 'quantite' => 2, 'unite' => 'unite']],
    ])->assertOk()->json('visite');
    expect($nouvelle['uuid'])->not->toBe($t['visite_uuid'])
        ->and($nouvelle['nom'])->toBe('Ousmane Diaby')
        ->and($nouvelle['reste'])->toBeGreaterThan(0);
});

it('fait une vente depuis la caisse d’une pharmacie : la visite du comptoir, les produits, l’encaissement', function () {
    [, , $produit] = pharmacie();
    // Les petits services de la pharmacie sont déjà tarifés.
    expect(collect($this->getJson('/api/tarifs')->json('data'))->pluck('nom')->all())->toContain('Prise de tension');

    $visite = $this->postJson('/api/visites', [])->assertCreated()->assertJsonPath('visite.nom', 'Client au comptoir')->json('visite');
    $this->postJson('/api/pharmacie/delivrances', [
        'visite_uuid' => $visite['uuid'],
        'lignes' => [['medicament_uuid' => $produit, 'quantite' => 1, 'unite' => 'boite']],
    ])->assertOk()->assertJsonPath('visite.uuid', $visite['uuid'])->assertJsonPath('visite.reste', 1500);

    $this->postJson('/api/caisse/ouvrir');
    $this->postJson("/api/visites/{$visite['uuid']}/encaisser", ['montant' => 1500, 'moyen' => 'especes'])->assertOk()
        ->assertJsonPath('visite.reste', 0);
});

it('vend et encaisse en un geste, compte par rayon, et retrouve la vente dans la journée', function () {
    [$etab, , $produit] = pharmacie();
    $capote = $this->postJson('/api/pharmacie/produits', [
        'nom' => 'Préservatifs Prudence x3', 'rayon' => 'contraception', 'conditionnement' => 1, 'prix_unitaire' => 250,
        'prix_achat' => 150, 'ordonnance_requise' => true,
    ])->assertCreated()->assertJsonPath('produit.rayon', 'contraception')
        // « Sur ordonnance » ne concerne que les médicaments.
        ->assertJsonPath('produit.ordonnance_requise', false)->json('produit.uuid');
    $this->postJson('/api/pharmacie/receptions', ['lignes' => [['medicament_uuid' => $capote, 'numero' => 'C1', 'peremption' => '2028-01-31', 'boites' => 10]]])->assertCreated();

    $vente = $this->postJson('/api/pharmacie/ventes', [
        'lignes' => [
            ['medicament_uuid' => $produit, 'quantite' => 1, 'unite' => 'boite'],
            ['medicament_uuid' => $capote, 'quantite' => 2],
        ],
        'client' => ['nom' => 'Moussa'],
        'paiement' => ['moyen' => 'especes'],
    ])->assertCreated()->json('vente');
    expect($vente['total'])->toBe(2000)->and($vente['statut'])->toBe('payee')->and($vente['client'])->toBe('Moussa')
        ->and($vente['lignes'])->toHaveCount(2);

    $jour = $this->getJson('/api/pharmacie/ventes')->assertOk();
    expect($jour->json('nombre'))->toBe(1)->and($jour->json('encaisse'))->toBe(2000)
        ->and($jour->json('par_moyen.especes'))->toBe(2000)
        ->and(collect($jour->json('par_rayon'))->pluck('montant', 'rayon')->all())->toBe(['medicaments' => 1500, 'contraception' => 500]);
    // Les plus vendus montent en tête de l'écran « Vendre ».
    expect(collect($this->getJson('/api/pharmacie/produits')->json('data'))->firstWhere('uuid', $capote)['ventes'])->toBe(1);
});

it('avec la caisse séparée, la vente attend le caissier, puis se clôt une fois payée', function () {
    [$etab, , $produit] = pharmacie();
    $etab->update(['caisse_separee' => true]);
    $vente = $this->postJson('/api/pharmacie/ventes', [
        'lignes' => [['medicament_uuid' => $produit, 'quantite' => 1, 'unite' => 'boite']],
        'paiement' => ['moyen' => 'especes'],
    ])->assertCreated()->assertJsonPath('vente.statut', 'a_encaisser')->json('vente');
    expect($this->getJson('/api/pharmacie/ventes')->json('a_encaisser'))->toBe(1500);

    $this->postJson('/api/caisse/ouvrir');
    $this->postJson("/api/visites/{$vente['uuid']}/encaisser", ['montant' => 1500, 'moyen' => 'especes'])->assertOk()
        ->assertJsonPath('visite.statut', 'cloturee');
});

it('dit quoi recommander, et à quel grossiste', function () {
    [, , $produit] = pharmacie();
    // 90 unités en stock, seuil 40 : rien. On en vend 60 : il en reste 30.
    expect($this->getJson('/api/pharmacie/a-commander')->json('data'))->toBe([]);
    $this->postJson('/api/pharmacie/ventes', ['lignes' => [['medicament_uuid' => $produit, 'quantite' => 2, 'unite' => 'boite']]])->assertCreated();
    $this->getJson('/api/pharmacie/a-commander')->assertJsonPath('data.0.uuid', $produit)
        ->assertJsonPath('data.0.stock_unites', 30)->assertJsonPath('data.0.boites', 2);
});
