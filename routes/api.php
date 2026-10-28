<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AbonnementController;
use App\Http\Controllers\Api\AgendaController;
use App\Http\Controllers\Api\AppareilController;
use App\Http\Controllers\Api\AssuranceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaisseController;
use App\Http\Controllers\Api\CompteController;
use App\Http\Controllers\Api\ConsoleController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\DroitsController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\HospitalisationController;
use App\Http\Controllers\Api\JournalAuditController;
use App\Http\Controllers\Api\LaboController;
use App\Http\Controllers\Api\MaterniteController;
use App\Http\Controllers\Api\MedicamentController;
use App\Http\Controllers\Api\MouvementStockController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Api\PatientsController;
use App\Http\Controllers\Api\PharmacieController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\RdvPatientController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\RendezVousController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TicketsPatientController;
use App\Http\Controllers\Api\VentePharmacieArticleController;
use App\Http\Controllers\Api\VentesPharmacieController;
use Illuminate\Support\Facades\Route;

// Ngoni Santé — référentiel public (pays, profils), mis en cache par l'app
Route::middleware('throttle:referentiel')->group(function () {
    Route::get('/pays', [ReferentielController::class, 'pays']);
    Route::get('/profils', [ReferentielController::class, 'profils']);
    Route::get('/options', [ReferentielController::class, 'options']);
});

// Ngoni Santé — compte de la personne (un numéro = une personne)
Route::prefix('compte')->group(function () {
    Route::middleware('throttle:sms')->post('/code', [CompteController::class, 'code']);
    Route::middleware('throttle:compte')->group(function () {
        Route::post('/inscription', [CompteController::class, 'inscription']);
        Route::post('/connexion', [CompteController::class, 'connexion']);
        Route::post('/pin-oublie', [CompteController::class, 'pinOublie']);
    });

    Route::middleware('auth:personne')->group(function () {
        Route::get('/', [CompteController::class, 'moi']);
        Route::patch('/', [CompteController::class, 'modifier']);
        Route::post('/espaces/{membreUuid}', [CompteController::class, 'ouvrirEspace']);
        Route::post('/etablissements', [CompteController::class, 'creerEtablissement']);
        Route::get('/tickets', [TicketsPatientController::class, 'mesTickets']);
        Route::post('/tickets', [TicketsPatientController::class, 'prendre']);
        Route::post('/tickets/{uuid}/annuler', [TicketsPatientController::class, 'annuler']);
        Route::get('/rdv', [RdvPatientController::class, 'mesRdv']);
        Route::post('/rdv', [RdvPatientController::class, 'reserver']);
        Route::post('/rdv/{uuid}/annuler', [RdvPatientController::class, 'annuler']);
        Route::post('/arrivee', [RdvPatientController::class, 'arrivee']);
        Route::get('/carte', [RdvPatientController::class, 'carte']);
        Route::get('/visites', [CaisseController::class, 'mesVisites']);
        Route::get('/ordonnances', [PharmacieController::class, 'mesOrdonnances']);
        Route::get('/dossier', [DossierController::class, 'monDossier']);
        Route::get('/maternite', [MaterniteController::class, 'maMaternite']);
        Route::patch('/dossier', [DossierController::class, 'modifierMonDossier']);
        Route::post('/dossier/accord', [DossierController::class, 'accord']);
        Route::delete('/dossier/acces/{id}', [DossierController::class, 'retirerAcces'])->whereNumber('id');
        Route::post('/carte/renouveler', [RdvPatientController::class, 'renouvelerCarte']);
        Route::post('/refresh', [CompteController::class, 'refresh']);
        Route::post('/deconnexion', [CompteController::class, 'deconnexion']);
    });
});

// La Console Ngoni (toutes les applications IsmaelDev), contrat v1.
Route::middleware(['cle-console', 'throttle:120,1'])->prefix('console/v1')->group(function () {
    Route::get('/resume', [ConsoleController::class, 'resume']);
    Route::get('/demandes', [ConsoleController::class, 'demandes']);
    Route::get('/demandes/{id}/preuve', [ConsoleController::class, 'preuve'])->whereUuid('id');
    Route::post('/demandes/{id}/approuver', [ConsoleController::class, 'approuver'])->whereUuid('id');
    Route::post('/demandes/{id}/refuser', [ConsoleController::class, 'refuser'])->whereUuid('id');
    Route::get('/comptes', [ConsoleController::class, 'comptes']);
    Route::get('/comptes/{id}', [ConsoleController::class, 'compte'])->whereUuid('id');
    Route::post('/comptes/{id}/revoquer', [ConsoleController::class, 'revoquer'])->whereUuid('id');
    Route::get('/recettes', [ConsoleController::class, 'recettes']);
    Route::post('/comptes/{id}/valider', [ConsoleController::class, 'valider'])->whereUuid('id');
    Route::post('/comptes/{id}/abonnement', [ConsoleController::class, 'accorder'])->whereUuid('id');
    Route::post('/comptes/{id}/interne', [ConsoleController::class, 'interne'])->whereUuid('id');
    Route::get('/formules', [ConsoleController::class, 'formules']);
    Route::patch('/formules/{code}', [ConsoleController::class, 'modifierFormule']);
});

// Public (app 3.x : inscription d'un établissement, connexion d'un membre)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public grand public / patients (recherche + prise de RDV), débit limité
Route::middleware('throttle:public')->prefix('public')->group(function () {
    Route::get('/etablissements', [PublicController::class, 'searchEtablissements']);
    Route::post('/rendez-vous', [PublicController::class, 'storeRendezVous']);
    Route::get('/etablissements/{uuid}/file', [TicketsPatientController::class, 'fileEtablissement']);
    Route::get('/tickets/{uuid}', [TicketsPatientController::class, 'suivi']);
    Route::get('/etablissements/{uuid}/agendas', [RdvPatientController::class, 'agendasEtablissement']);
    Route::get('/agendas/{uuid}/creneaux', [RdvPatientController::class, 'creneaux']);
    Route::get('/ordonnances/cle', [PharmacieController::class, 'clePublique']);
});

// Écrans de salle et bornes (appairés, sans compte personnel)
Route::middleware('throttle:appairage')->post('/appareils/appairer', [AppareilController::class, 'appairer']);
Route::middleware(['appareil', 'throttle:appareil'])->prefix('appareil')->group(function () {
    Route::get('/etat', [AppareilController::class, 'etat']);
    Route::get('/services', [AppareilController::class, 'services']);
    Route::post('/tickets', [AppareilController::class, 'emettre']);
    Route::post('/scan', [RdvPatientController::class, 'scanBorne']);
});

// Protected
Route::middleware('auth:api')->group(function () {
    // L'abonnement de l'établissement (ses administrateurs, même échu).
    Route::get('/abonnement', [AbonnementController::class, 'etat']);
    Route::post('/abonnement/demandes', [AbonnementController::class, 'demander']);
    Route::delete('/abonnement/demandes/{uuid}', [AbonnementController::class, 'annuler'])->whereUuid('uuid');
    // Auth / users
    Route::post('/deconnexion', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // File d'attente (Ngoni Santé, lot 2)
    Route::prefix('file')->group(function () {
        Route::middleware('droit:file.emettre|file.appeler|file.gerer')->group(function () {
            Route::get('/services', [FileController::class, 'services']);
            Route::get('/tickets', [FileController::class, 'tickets']);
            Route::get('/etat', [FileController::class, 'etat']);
        });
        Route::middleware('droit:file.emettre')->post('/tickets', [FileController::class, 'emettre']);
        Route::middleware('droit:file.appeler')->group(function () {
            Route::post('/appeler', [FileController::class, 'appeler']);
            Route::post('/tickets/{uuid}/{action}', [FileController::class, 'action'])
                ->whereIn('action', ['rappeler', 'servir', 'terminer', 'absent', 'annuler', 'transferer']);
        });
        Route::middleware('droit:file.gerer|stats.voir')
            ->get('/statistiques', [FileController::class, 'statistiques']);
        Route::middleware('droit:file.gerer')->group(function () {
            Route::post('/services', [FileController::class, 'creerService']);
            Route::patch('/services/{uuid}', [FileController::class, 'modifierService']);
            Route::delete('/services/{uuid}', [FileController::class, 'supprimerService']);
            Route::post('/services/{uuid}/points', [FileController::class, 'creerPoint']);
            Route::patch('/points/{uuid}', [FileController::class, 'modifierPoint']);
            Route::delete('/points/{uuid}', [FileController::class, 'supprimerPoint']);
            Route::get('/appareils', [AppareilController::class, 'index']);
            Route::middleware('fonction:ecrans')->group(function () {
                Route::post('/appareils', [AppareilController::class, 'creer']);
                Route::post('/appareils/{uuid}/code', [AppareilController::class, 'code']);
            });
            Route::delete('/appareils/{uuid}', [AppareilController::class, 'revoquer']);
        });
    });

    // Rendez-vous et agendas (Ngoni Santé, lot 3)
    Route::middleware('droit:rdv.voir|rdv.gerer')->group(function () {
        Route::get('/agendas', [AgendaController::class, 'index']);
        Route::get('/agendas/{uuid}/creneaux', [AgendaController::class, 'creneaux']);
        Route::get('/rdv', [AgendaController::class, 'rendezVous']);
    });
    Route::middleware('droit:rdv.gerer')->group(function () {
        Route::post('/agendas', [AgendaController::class, 'creer']);
        Route::patch('/agendas/{uuid}', [AgendaController::class, 'modifier']);
        Route::delete('/agendas/{uuid}', [AgendaController::class, 'supprimer']);
        Route::post('/agendas/{uuid}/absences', [AgendaController::class, 'ajouterAbsence']);
        Route::delete('/absences/{id}', [AgendaController::class, 'supprimerAbsence'])->whereNumber('id');
        Route::post('/rdv', [AgendaController::class, 'reserver']);
        Route::post('/rdv/{uuid}/{action}', [AgendaController::class, 'action'])
            ->whereIn('action', ['confirmer', 'refuser', 'annuler', 'absent', 'arrivee']);
    });
    Route::middleware('droit:file.emettre|rdv.gerer')->post('/accueil/scan', [AgendaController::class, 'scanner']);
    Route::middleware('droit:file.gerer|rdv.gerer')->get('/accueil/qr', [AgendaController::class, 'qrAccueil']);

    // Visite et caisse unifiée (Ngoni Santé, lot 4)
    $soins = 'caisse.encaisser|consultation.faire|pharmacie.delivrer|labo.saisir|soins.administrer';
    Route::middleware("droit:{$soins}|caisse.rapports")->group(function () {
        Route::get('/tarifs', [CaisseController::class, 'tarifs']);
        Route::get('/visites', [CaisseController::class, 'visites']);
        Route::get('/visites/{uuid}', [CaisseController::class, 'visite']);
    });
    Route::middleware('droit:caisse.encaisser|pharmacie.delivrer')->post('/visites', [CaisseController::class, 'creerVisite']);
    Route::middleware("droit:{$soins}")->group(function () {
        Route::post('/visites/{uuid}/lignes', [CaisseController::class, 'ajouterLigne']);
        Route::delete('/visites/{uuid}/lignes/{ligne}', [CaisseController::class, 'retirerLigne']);
    });
    Route::middleware('droit:caisse.rapports|etablissement.regler')->group(function () {
        Route::post('/tarifs', [CaisseController::class, 'creerTarif']);
        Route::patch('/tarifs/{uuid}', [CaisseController::class, 'modifierTarif']);
        Route::delete('/tarifs/{uuid}', [CaisseController::class, 'supprimerTarif']);
    });
    Route::middleware('droit:caisse.encaisser')->group(function () {
        Route::post('/visites/{uuid}/encaisser', [CaisseController::class, 'encaisser']);
        Route::post('/visites/{uuid}/cloturer', [CaisseController::class, 'cloturerVisite']);
        Route::get('/caisse/session', [CaisseController::class, 'session']);
        Route::post('/caisse/ouvrir', [CaisseController::class, 'ouvrir']);
        Route::post('/caisse/cloturer', [CaisseController::class, 'cloturer']);
    });
    Route::middleware('droit:caisse.rapports')->get('/caisse/rapport', [CaisseController::class, 'rapport']);

    // Pharmacie et ordonnances (Ngoni Santé, lot 5)
    Route::prefix('pharmacie')->group(function () {
        // Les produits : aussi au médecin qui prescrit (prix et stock).
        Route::middleware('droit:pharmacie.delivrer|stock.gerer|ordonnance.prescrire')->get('/produits', [PharmacieController::class, 'produits']);
        Route::middleware('droit:pharmacie.delivrer|stock.gerer')->get('/alertes', [PharmacieController::class, 'alertes']);
        Route::middleware('droit:pharmacie.delivrer')->get('/a-servir', [PharmacieController::class, 'aServir']);
        Route::middleware('droit:pharmacie.delivrer')->post('/ventes', [PharmacieController::class, 'vendre']);
        Route::middleware('droit:pharmacie.delivrer|caisse.encaisser')->post('/ventes/{uuid}/annuler', [PharmacieController::class, 'annulerVente'])->whereUuid('uuid');
        Route::middleware('droit:pharmacie.delivrer|caisse.encaisser|caisse.rapports')->get('/ventes', [PharmacieController::class, 'ventes']);
        Route::middleware('droit:stock.gerer|pharmacie.delivrer')->get('/a-commander', [PharmacieController::class, 'aCommander']);
        Route::middleware('droit:stock.gerer')->group(function () {
            Route::post('/produits', [PharmacieController::class, 'creerProduit']);
            Route::patch('/produits/{uuid}', [PharmacieController::class, 'modifierProduit']);
            Route::get('/fournisseurs', [PharmacieController::class, 'fournisseurs']);
            Route::post('/fournisseurs', [PharmacieController::class, 'creerFournisseur']);
            Route::post('/receptions', [PharmacieController::class, 'recevoir']);
            Route::post('/lots/{uuid}/inventaire', [PharmacieController::class, 'inventorier']);
        });
        Route::middleware('droit:pharmacie.delivrer')->group(function () {
            Route::post('/ordonnances/lire', [PharmacieController::class, 'lireOrdonnance']);
            Route::post('/delivrances', [PharmacieController::class, 'delivrer']);
        });
    });
    Route::middleware('droit:ordonnance.prescrire')->post('/ordonnances', [PharmacieController::class, 'prescrire']);
    Route::middleware('droit:ordonnance.prescrire|pharmacie.delivrer')->get('/ordonnances', [PharmacieController::class, 'ordonnances']);

    // Patients : identité et visites, rien de médical (le dossier est à part)
    Route::prefix('patients')->group(function () {
        Route::middleware('droit:patients.voir|dossier.lire')->group(function () {
            Route::get('/', [PatientsController::class, 'index']);
            Route::get('/{uuid}', [PatientsController::class, 'show'])->whereUuid('uuid');
        });
        Route::middleware('droit:patients.gerer')->group(function () {
            Route::post('/', [PatientsController::class, 'creer']);
            Route::patch('/{uuid}', [PatientsController::class, 'modifier'])->whereUuid('uuid');
            Route::post('/{uuid}/nouveau-pin', [PatientsController::class, 'nouveauPin'])->whereUuid('uuid');
            Route::post('/{uuid}/fusionner', [PatientsController::class, 'fusionner'])->whereUuid('uuid');
            Route::post('/{uuid}/relier', [PatientsController::class, 'relier'])->whereUuid('uuid');
        });
    });

    // Dossier patient et consultation (Ngoni Santé, lot 6)
    Route::middleware('droit:dossier.lire')->group(function () {
        Route::get('/dossiers', [DossierController::class, 'index']);
        Route::get('/dossiers/{uuid}', [DossierController::class, 'show']);
        Route::get('/visites/{uuid}/dossier', [DossierController::class, 'pourVisite']);
        Route::post('/dossiers/{uuid}/acces/code', [DossierController::class, 'demanderCode']);
        Route::post('/dossiers/{uuid}/acces', [DossierController::class, 'accorderParCode']);
        Route::post('/dossiers/acces-qr', [DossierController::class, 'accorderParQr']);
        Route::post('/dossiers/{uuid}/acces/sur-place', [DossierController::class, 'accorderSurPlace']);
    });
    Route::middleware('droit:dossier.ecrire')->patch('/dossiers/{uuid}', [DossierController::class, 'modifier']);
    Route::middleware('droit:consultation.faire')->post('/visites/{uuid}/consultation', [DossierController::class, 'consulter']);
    Route::middleware('droit:dossier.ecrire|consultation.faire')->post('/visites/{uuid}/tri', [DossierController::class, 'trier']);

    // Laboratoire (Ngoni Santé, lot 7)
    Route::prefix('labo')->group(function () {
        Route::middleware('droit:labo.saisir|labo.valider|consultation.faire')->get('/analyses', [LaboController::class, 'analyses']);
        Route::middleware('droit:labo.valider|etablissement.regler')->group(function () {
            Route::post('/analyses', [LaboController::class, 'creerAnalyse']);
            Route::patch('/analyses/{uuid}', [LaboController::class, 'modifierAnalyse']);
        });
        Route::middleware('droit:labo.saisir|consultation.faire')->post('/demandes', [LaboController::class, 'demander']);
        Route::middleware('droit:labo.saisir|labo.valider')->group(function () {
            Route::get('/demandes', [LaboController::class, 'demandes']);
            Route::post('/demandes/lire', [LaboController::class, 'lire']);
            Route::get('/demandes/{uuid}', [LaboController::class, 'show']);
        });
        Route::middleware('droit:labo.saisir')->group(function () {
            Route::post('/demandes/{uuid}/prelever', [LaboController::class, 'prelever']);
            Route::post('/demandes/{uuid}/resultats', [LaboController::class, 'saisir']);
        });
        Route::middleware('droit:labo.valider')->post('/demandes/{uuid}/valider', [LaboController::class, 'valider']);
        Route::middleware('droit:labo.saisir|consultation.faire')->post('/demandes/{uuid}/annuler', [LaboController::class, 'annuler']);
    });

    // Maternité et vaccination (Ngoni Santé, lot 7)
    Route::middleware('droit:maternite.suivre')->group(function () {
        Route::get('/maternite/grossesses', [MaterniteController::class, 'index']);
        Route::get('/maternite/grossesses/{uuid}', [MaterniteController::class, 'show']);
        Route::post('/maternite/grossesses/{uuid}/cpn', [MaterniteController::class, 'cpn']);
        Route::post('/maternite/grossesses/{uuid}/accouchement', [MaterniteController::class, 'accoucher']);
        Route::get('/visites/{uuid}/grossesse', [MaterniteController::class, 'pourVisite']);
        Route::post('/visites/{uuid}/grossesse', [MaterniteController::class, 'declarer']);
    });
    Route::middleware('droit:maternite.suivre|dossier.ecrire')->group(function () {
        Route::get('/dossiers/{uuid}/vaccins', [MaterniteController::class, 'carnet']);
        Route::post('/dossiers/{uuid}/vaccins', [MaterniteController::class, 'vacciner']);
    });

    // Hospitalisation (Ngoni Santé, lot 8)
    Route::prefix('hospitalisation')->group(function () {
        Route::middleware('droit:hospitalisation.gerer|soins.administrer')->group(function () {
            Route::get('/chambres', [HospitalisationController::class, 'chambres']);
            Route::get('/sejours', [HospitalisationController::class, 'sejours']);
            Route::post('/sejours/lire', [HospitalisationController::class, 'lire']);
            Route::get('/sejours/{uuid}', [HospitalisationController::class, 'show']);
        });
        Route::middleware('droit:hospitalisation.gerer|etablissement.regler')->group(function () {
            Route::post('/chambres', [HospitalisationController::class, 'creerChambre']);
            Route::patch('/chambres/{uuid}', [HospitalisationController::class, 'modifierChambre']);
        });
        Route::middleware('droit:hospitalisation.gerer')->group(function () {
            Route::post('/sejours/{uuid}/transferer', [HospitalisationController::class, 'transferer']);
            Route::post('/sejours/{uuid}/sortie', [HospitalisationController::class, 'sortie']);
        });
        Route::middleware('droit:ordonnance.prescrire')->group(function () {
            Route::post('/sejours/{uuid}/soins', [HospitalisationController::class, 'prescrire']);
            Route::post('/soins/{uuid}/arreter', [HospitalisationController::class, 'arreter']);
        });
        Route::middleware('droit:soins.administrer')->post('/sejours/{uuid}/administrations', [HospitalisationController::class, 'administrer']);
    });
    Route::middleware('droit:hospitalisation.gerer')->post('/visites/{uuid}/hospitalisation', [HospitalisationController::class, 'admettre']);

    // Badges du personnel et visiteurs (Ngoni Santé, lot 8)
    Route::middleware('droit:badges.gerer')->group(function () {
        Route::get('/badges', [PresenceController::class, 'badges']);
        Route::post('/badges/{uuid}/renouveler', [PresenceController::class, 'renouveler']);
    });
    Route::middleware('droit:badges.gerer|file.emettre')->group(function () {
        Route::post('/pointages', [PresenceController::class, 'pointer']);
        Route::get('/pointages/presents', [PresenceController::class, 'presents']);
    });
    Route::middleware('droit:badges.gerer|stats.voir')->get('/pointages/rapport', [PresenceController::class, 'rapport']);
    Route::middleware('droit:file.emettre|hospitalisation.gerer')->group(function () {
        Route::get('/visiteurs', [PresenceController::class, 'visiteurs']);
        Route::get('/visiteurs/patients', [PresenceController::class, 'patients']);
        Route::post('/visiteurs', [PresenceController::class, 'entrer']);
        Route::post('/visiteurs/sortie', [PresenceController::class, 'sortir']);
    });

    // Assurances (Ngoni Santé, lot 8)
    Route::middleware('droit:caisse.encaisser|assurances.gerer|caisse.rapports')->get('/assureurs', [AssuranceController::class, 'index']);
    Route::middleware('droit:assurances.gerer|etablissement.regler')->group(function () {
        Route::post('/assureurs', [AssuranceController::class, 'creer']);
        Route::patch('/assureurs/{uuid}', [AssuranceController::class, 'modifier']);
    });
    Route::middleware('droit:caisse.encaisser|assurances.gerer')->group(function () {
        Route::post('/visites/{uuid}/assurance', [AssuranceController::class, 'couvrir']);
        Route::delete('/visites/{uuid}/assurance', [AssuranceController::class, 'retirer']);
    });
    Route::middleware('droit:assurances.gerer|caisse.rapports')->get('/assurances/bordereau', [AssuranceController::class, 'bordereau']);

    // Droits par rôle (Ngoni Santé)
    Route::middleware('droit:etablissement.regler')->group(function () {
        Route::get('/droits', [DroitsController::class, 'index']);
        Route::put('/droits/{role}', [DroitsController::class, 'modifier']);
        Route::delete('/droits/{role}', [DroitsController::class, 'reinitialiser']);
    });
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/users', [AuthController::class, 'addUser']);
    Route::patch('/users/{user}', [AuthController::class, 'update']);
    Route::delete('/users/{user}', [AuthController::class, 'deleteUser']);

    // Etablissements
    Route::post('/etablissements', [EtablissementController::class, 'store']);
    Route::get('/etablissements', [EtablissementController::class, 'index']);
    Route::get('/etablissements/{etablissement}/show', [EtablissementController::class, 'show']);
    Route::get('/etablissements/users', [EtablissementController::class, 'usersDeMesEtablissements']);
    Route::patch('/etablissements/{etablissement}', [EtablissementController::class, 'update']);
    Route::delete('/etablissements/{etablissement}', [EtablissementController::class, 'destroy']);

    // Synchronisation offline
    Route::post('/sync/pull', [SyncController::class, 'pull']);
    Route::post('/sync/push', [SyncController::class, 'push']);

    // Statistiques (admin)
    Route::get('/stats/users', [StatsController::class, 'users']);
    Route::middleware(['droit:stats.voir|caisse.rapports|etablissement.regler', 'fonction:rapport'])->get('/rapports/mensuel', [StatsController::class, 'rapportMensuel']);

    // Rendez-vous (côté personnel)
    Route::get('/rendez-vous', [RendezVousController::class, 'index']);
    Route::patch('/rendez-vous/{rendezVous}', [RendezVousController::class, 'update']);

    // Ressources metier
    Route::apiResource('medicaments', MedicamentController::class)->except(['create', 'edit']);
    Route::apiResource('mouvement-stocks', MouvementStockController::class)->except(['create', 'edit']);
    Route::apiResource('paiements', PaiementController::class)->except(['create', 'edit']);
    Route::apiResource('consultations', ConsultationController::class)->except(['create', 'edit']);
    Route::apiResource('ventes-pharmacie', VentesPharmacieController::class)->except(['create', 'edit']);
    Route::apiResource('vente-pharmacie-articles', VentePharmacieArticleController::class)->except(['create', 'edit']);
    Route::apiResource('journal-audits', JournalAuditController::class)->except(['create', 'edit']);

});
