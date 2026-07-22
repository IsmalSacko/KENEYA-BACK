<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\JournalAuditController;
use App\Http\Controllers\Api\MedicamentController;
use App\Http\Controllers\Api\MouvementStockController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\VentePharmacieArticleController;
use App\Http\Controllers\Api\VentesPharmacieController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    // Auth / users
    Route::post('/deconnexion', [AuthController::class, 'logout']);
    Route::post('/users', [AuthController::class, 'addUser']);
    Route::patch('/users/{id}', [AuthController::class, 'update']);
    Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);

    // Etablissements
    Route::get('/etablissements', [EtablissementController::class, 'index']);
    Route::get('/etablissements/{etablissement}/show', [EtablissementController::class, 'show']);
    Route::get('/etablissements/users', [EtablissementController::class, 'usersDeMesEtablissements']);
    Route::patch('/etablissements/{etablissement}', [EtablissementController::class, 'update']);
    Route::delete('/etablissements/{etablissement}', [EtablissementController::class, 'destroy']);

    // Ressources metier
    Route::apiResource('patients', PatientController::class)->except(['create', 'edit']);
    Route::apiResource('medicaments', MedicamentController::class)->except(['create', 'edit']);
    Route::apiResource('mouvement-stocks', MouvementStockController::class)->except(['create', 'edit']);
    Route::apiResource('paiements', PaiementController::class)->except(['create', 'edit']);
    Route::apiResource('consultations', ConsultationController::class)->except(['create', 'edit']);
    Route::apiResource('ventes-pharmacie', VentesPharmacieController::class)->except(['create', 'edit']);
    Route::apiResource('vente-pharmacie-articles', VentePharmacieArticleController::class)->except(['create', 'edit']);
    Route::apiResource('journal-audits', JournalAuditController::class)->except(['create', 'edit']);

});
