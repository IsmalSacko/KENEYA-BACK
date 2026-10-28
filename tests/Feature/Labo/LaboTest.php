<?php

declare(strict_types=1);

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\Tarif;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function membreLabo(Etablissement $etab, string $role): User
{
    $m = User::where(['etablissement_id' => $etab->id, 'role' => $role])->first()
        ?? User::factory()->create(['etablissement_id' => $etab->id, 'role' => $role, 'name' => ucfirst($role)]);
    auth()->forgetGuards();
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    test()->actingAs($m, 'api');

    return $m;
}

/** Un centre de santé, une visite à l'accueil, et le catalogue avec prix. */
function centreAvecLabo(?string $telephone = '+22370000055'): array
{
    $etab = Etablissement::factory()->create(['nom' => 'CSCom Sabalibougou', 'profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    membreLabo($etab, 'admin_etablissement');
    test()->getJson('/api/file/services')->assertOk();
    $catalogue = collect(test()->getJson('/api/labo/analyses')->assertOk()->json('data'))->keyBy('code');
    test()->patchJson("/api/labo/analyses/{$catalogue['GE']['uuid']}", ['prix' => 1000])->assertOk();
    test()->patchJson("/api/labo/analyses/{$catalogue['GLY']['uuid']}", ['prix' => 1500])->assertOk();
    $catalogue = collect(test()->getJson('/api/labo/analyses')->json('data'))->keyBy('code');

    membreLabo($etab, 'accueil');
    $visite = test()->postJson('/api/file/tickets', array_filter([
        'service_uuid' => Service::where('etablissement_id', $etab->id)->where('prefixe', 'A')->value('uuid'),
        'nom' => 'Fanta Keïta', 'telephone' => $telephone,
    ]))->assertStatus(201)->json('ticket.visite_uuid');

    return [$etab, $visite, $catalogue];
}

function demanderAnalyses(Etablissement $etab, string $visite, array $analyses): array
{
    membreLabo($etab, 'medecin');

    return test()->postJson('/api/labo/demandes', [
        'visite_uuid' => $visite, 'analyses' => $analyses, 'renseignements' => 'Fièvre 39 °C',
    ])->assertStatus(201)->json('demande');
}

it('donne un catalogue de départ avec les valeurs normales', function () {
    [, , $catalogue] = centreAvecLabo();

    expect($catalogue->keys())->toContain('TDR', 'GE', 'HB', 'GLY', 'VIH')
        ->and($catalogue['GLY']['normale'])->toBe('0,7 – 1,1 g/L')
        ->and($catalogue['GE']['prix'])->toBe(1000)
        ->and($catalogue['GE']['choix'])->toContain('Positif ++');
});

it('facture sur la visite les analyses demandées par le médecin', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['GE']['uuid'], $c['GLY']['uuid'], $c['NFS']['uuid']]);

    expect($d['statut'])->toBe('demandee')->and($d['lignes'])->toHaveCount(3)
        ->and($d['qr'])->toBe("ngoni-sante:labo:{$d['uuid']}")
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(2500); // la NFS n'a pas de prix
});

it('prélève, saisit, marque les résultats anormaux et valide', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['GE']['uuid'], $c['GLY']['uuid'], $c['NFS']['uuid']]);
    [$ge, $gly, $nfs] = collect($d['lignes'])->pluck('uuid')->all();

    membreLabo($etab, 'laborantin');
    $this->postJson('/api/labo/demandes/lire', ['qr' => $d['qr']])->assertOk()->assertJsonPath('demande.patient', 'Fanta Keïta');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/prelever")->assertOk()->assertJsonPath('demande.statut', 'prelevee');

    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $gly, 'resultat' => 'beaucoup']]])
        ->assertStatus(422)->assertJsonPath('message', 'Glycémie à jeun : un nombre est attendu.');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $ge, 'resultat' => 'Peut-être']]])
        ->assertStatus(422);

    $saisie = $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [
        ['uuid' => $ge, 'resultat' => 'Positif ++'],
        ['uuid' => $gly, 'resultat' => '0,95'],
    ]])->assertOk()->json('demande.lignes');
    expect(collect($saisie)->pluck('anormal')->all())->toBe([true, false, null]);

    // On valide une fois tout saisi ; la caisse ne valide pas.
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider")->assertStatus(422);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $nfs, 'resultat' => 'GB 6 000/mm³, normale']]]);
    membreLabo($etab, 'caissier');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider")->assertForbidden();
    membreLabo($etab, 'laborantin');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider")->assertOk()->assertJsonPath('demande.statut', 'validee');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $nfs, 'resultat' => 'x']]])->assertStatus(422);
});

it('prévient le patient par SMS sans donner le résultat', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['TDR']['uuid']]);
    membreLabo($etab, 'admin_etablissement');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $d['lignes'][0]['uuid'], 'resultat' => 'Positif']]]);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider")->assertOk();

    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    $dernier = end($sms->envoyes);
    expect($dernier['telephone'])->toBe('+22370000055')
        ->and($dernier['message'])->toContain('résultats')->toContain('CSCom Sabalibougou')
        ->not->toContain('Positif');
});

it('montre les résultats dans le dossier seulement une fois validés', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['TDR']['uuid']]);

    $avant = $this->getJson("/api/visites/{$visite}/dossier")->json('dossier.analyses.0');
    expect($avant['statut'])->toBe('demandee')->and($avant['lignes'][0]['resultat'])->toBeNull();

    membreLabo($etab, 'admin_etablissement');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $d['lignes'][0]['uuid'], 'resultat' => 'Négatif']]]);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider");
    membreLabo($etab, 'medecin');
    $apres = $this->getJson("/api/visites/{$visite}/dossier")->json('dossier.analyses.0');
    expect($apres['lignes'][0]['resultat'])->toBe('Négatif')->and($apres['lignes'][0]['anormal'])->toBeFalse();
});

it('liste le travail du labo, les urgences d’abord', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    demanderAnalyses($etab, $visite, [$c['HB']['uuid']]);
    membreLabo($etab, 'medecin');
    $urgent = $this->postJson('/api/labo/demandes', ['visite_uuid' => $visite, 'analyses' => [$c['GLY']['uuid']], 'urgent' => true])->json('demande.uuid');

    membreLabo($etab, 'laborantin');
    expect($this->getJson('/api/labo/demandes')->json('data.0.uuid'))->toBe($urgent)
        ->and($this->getJson('/api/labo/demandes?statut=validee')->json('data'))->toBe([]);
});

it('annule une demande, prélevée ou non, et retire ses analyses de la facture', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $a = demanderAnalyses($etab, $visite, [$c['GE']['uuid']]);
    $b = demanderAnalyses($etab, $visite, [$c['GLY']['uuid'], $c['NFS']['uuid']]);
    expect(Visite::where('uuid', $visite)->value('total'))->toBe(2500);

    $r = $this->postJson("/api/labo/demandes/{$a['uuid']}/annuler", ['motif' => 'Mauvais patient'])->assertOk()->json();
    expect($r['demande']['statut'])->toBe('annulee')->and($r['demande']['motif_annulation'])->toBe('Mauvais patient')
        ->and($r['note'])->toBeNull()
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(1500);
    $this->postJson("/api/labo/demandes/{$a['uuid']}/annuler")->assertStatus(422);

    membreLabo($etab, 'laborantin');
    $this->postJson("/api/labo/demandes/{$b['uuid']}/prelever");
    $this->postJson("/api/labo/demandes/{$b['uuid']}/annuler")->assertOk()->assertJsonPath('demande.statut', 'annulee');
    expect(Visite::where('uuid', $visite)->value('total'))->toBe(0);
});

it('garde les analyses payées sur la facture, et le dit', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['GE']['uuid']]);
    Visite::where('uuid', $visite)->update(['statut' => 'cloturee']);

    expect($this->postJson("/api/labo/demandes/{$d['uuid']}/annuler")->assertOk()->json('note'))->toContain('remboursez')
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(1000);
});

it('ne valide ni n’annule une demande validée', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['TDR']['uuid']]);
    membreLabo($etab, 'admin_etablissement');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $d['lignes'][0]['uuid'], 'resultat' => 'Négatif']]]);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider")->assertOk();
    $this->postJson("/api/labo/demandes/{$d['uuid']}/annuler")->assertStatus(422);
});

it('ne laisse ni la caisse ni un autre centre voir les demandes', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['HB']['uuid']]);

    membreLabo($etab, 'caissier');
    $this->getJson("/api/labo/demandes/{$d['uuid']}")->assertForbidden();

    $autre = Etablissement::factory()->create(['profil' => 'centre_sante', 'type' => null, 'modules' => null]);
    membreLabo($autre, 'admin_etablissement');
    $this->getJson("/api/labo/demandes/{$d['uuid']}")->assertNotFound();
    $this->postJson("/api/labo/demandes/{$d['uuid']}/prelever")->assertNotFound(); // pas un 422
    $this->postJson('/api/labo/demandes', ['visite_uuid' => $visite, 'analyses' => [$c['HB']['uuid']]])->assertNotFound();
});

it('ajoute une analyse au catalogue, avec son prix', function () {
    [$etab] = centreAvecLabo();
    membreLabo($etab, 'admin_etablissement');
    $a = $this->postJson('/api/labo/analyses', [
        'code' => 'CRP', 'nom' => 'CRP', 'categorie' => 'biochimie', 'type_resultat' => 'nombre',
        'unite' => 'mg/L', 'normale_min' => 0, 'normale_max' => 6, 'prix' => 3000,
    ])->assertStatus(201)->json('analyse');
    $this->postJson('/api/labo/analyses', ['code' => 'CRP', 'nom' => 'Doublon', 'type_resultat' => 'texte'])->assertStatus(422);

    expect($a['prix'])->toBe(3000)->and($a['normale'])->toBe('0 – 6 mg/L');
    $this->patchJson("/api/labo/analyses/{$a['uuid']}", ['prix' => 0])->assertOk()->assertJsonPath('analyse.prix', null);
});

it('accepte une analyse hors catalogue, écrite par le médecin', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    membreLabo($etab, 'medecin');
    $d = $this->postJson('/api/labo/demandes', [
        'visite_uuid' => $visite, 'analyses' => [$c['GE']['uuid']], 'autres' => ['CRP', '  ', 'CRP'],
    ])->assertStatus(201)->json('demande');

    expect(collect($d['lignes'])->pluck('nom')->all())->toBe(['Goutte épaisse', 'CRP'])
        ->and($d['lignes'][1]['type_resultat'])->toBe('texte')
        ->and(Visite::where('uuid', $visite)->value('total'))->toBe(1000); // la CRP n'a pas de prix
    $this->postJson('/api/labo/demandes', ['visite_uuid' => $visite, 'autres' => ['Sérologie toxoplasmose']])->assertStatus(201);
    $this->postJson('/api/labo/demandes', ['visite_uuid' => $visite])->assertStatus(422);
});

it('rejette un échantillon : la demande revient à prélever, les résultats s’effacent', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['HB']['uuid']]);
    membreLabo($etab, 'laborantin');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/rejeter", ['motif' => 'Hémolysé'])->assertStatus(422);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $d['lignes'][0]['uuid'], 'resultat' => '11']]]);

    $r = $this->postJson("/api/labo/demandes/{$d['uuid']}/rejeter", ['motif' => 'Hémolysé'])->assertOk()->json('demande');
    expect($r['statut'])->toBe('demandee')->and($r['rejet_motif'])->toBe('Hémolysé')
        ->and($r['lignes'][0]['resultat'])->toBeNull()
        ->and($r['lignes'][0]['uuid'])->toBe($d['lignes'][0]['uuid']);
});

it('corrige un résultat validé avec la raison, garde l’ancien et prévient le patient', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['GLY']['uuid']]);
    $ligne = $d['lignes'][0]['uuid'];
    membreLabo($etab, 'admin_etablissement');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $ligne, 'resultat' => '0,95', 'commentaire' => 'À jeun']]])
        ->assertJsonPath('demande.lignes.0.commentaire', 'À jeun');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $ligne, 'resultat' => '0,95']]])
        ->assertJsonPath('demande.lignes.0.commentaire', 'À jeun');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $ligne, 'resultat' => '0,95', 'commentaire' => null]]])
        ->assertJsonPath('demande.lignes.0.commentaire', null);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $ligne, 'resultat' => '0,95', 'commentaire' => 'À jeun']]]);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/corriger", ['motif' => 'X', 'resultats' => [['uuid' => $ligne, 'resultat' => '1,5']]])
        ->assertStatus(422);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider")->assertOk();

    membreLabo($etab, 'medecin');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/corriger", ['motif' => 'X', 'resultats' => [['uuid' => $ligne, 'resultat' => '1,5']]])
        ->assertForbidden(); // seul qui valide corrige
    membreLabo($etab, 'admin_etablissement');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/corriger", ['motif' => 'Inversion de tubes', 'resultats' => [['uuid' => $ligne, 'resultat' => '0,95']]])
        ->assertStatus(422)->assertJsonPath('message', 'Aucun résultat n’a changé.');
    $l = $this->postJson("/api/labo/demandes/{$d['uuid']}/corriger", ['motif' => 'Inversion de tubes', 'resultats' => [['uuid' => $ligne, 'resultat' => '1,5']]])
        ->assertOk()->json('demande.lignes.0');

    expect($l['resultat'])->toBe('1,5')->and($l['anormal'])->toBeTrue()->and($l['commentaire'])->toBe('À jeun')
        ->and($l['corrections'][0]['ancien'])->toBe('0,95')->and($l['corrections'][0]['motif'])->toBe('Inversion de tubes');
    $sms = app(EnvoiSms::class);
    assert($sms instanceof JournalSms);
    expect(end($sms->envoyes)['message'])->toContain('corrigés')->not->toContain('1,5');
});

it('cherche un patient dans les listes, et les validées sur plusieurs jours', function () {
    [$etab, $visite, $c] = centreAvecLabo();
    $d = demanderAnalyses($etab, $visite, [$c['TDR']['uuid']]);
    membreLabo($etab, 'admin_etablissement');
    $this->postJson("/api/labo/demandes/{$d['uuid']}/resultats", ['resultats' => [['uuid' => $d['lignes'][0]['uuid'], 'resultat' => 'Négatif']]]);
    $this->postJson("/api/labo/demandes/{$d['uuid']}/valider");
    $this->travel(3)->days();

    expect($this->getJson('/api/labo/demandes?statut=validee')->json('data'))->toBe([])
        ->and($this->getJson('/api/labo/demandes?statut=validee&jours=7&q=fanta')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/labo/demandes?statut=validee&jours=7&q=moussa')->json('data'))->toBe([]);
});

it('renomme une analyse et son acte au tarif, ajoute une analyse à choix', function () {
    [$etab, , $c] = centreAvecLabo();
    membreLabo($etab, 'admin_etablissement');
    $this->patchJson("/api/labo/analyses/{$c['GE']['uuid']}", ['nom' => 'Goutte épaisse (GE)', 'categorie' => 'hematologie'])->assertOk()
        ->assertJsonPath('analyse.nom', 'Goutte épaisse (GE)')->assertJsonPath('analyse.categorie', 'hematologie');
    expect(Tarif::where('etablissement_id', $etab->id)->where('nom', 'Goutte épaisse (GE)')->exists())->toBeTrue();

    $a = $this->postJson('/api/labo/analyses', [
        'code' => 'HBS', 'nom' => 'Antigène HBs', 'categorie' => 'serologie', 'type_resultat' => 'choix',
        'choix' => ['Négatif', 'Positif'], 'choix_normal' => 'Négatif',
    ])->assertStatus(201)->json('analyse');
    expect($a['choix'])->toBe(['Négatif', 'Positif']);
});
