<?php

declare(strict_types=1);

use App\Http\Controllers\Web\SuiviTicketController;
use App\Http\Controllers\Web\SuppressionCompteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Suivi d'un ticket de file d'attente (QR imprimé), sans l'app.
Route::get('/t/{uuid}', [SuiviTicketController::class, 'show'])
    ->middleware('throttle:web')
    ->whereUuid('uuid')
    ->name('ticket.suivi');

// Politique de confidentialité, demandée par le Play Store.
Route::view('/confidentialite', 'confidentialite')->name('confidentialite');

// Page publique de demande de suppression de compte / de données (RGPD, Play Store).
Route::get('/suppression-compte', [SuppressionCompteController::class, 'show'])
    ->name('suppression-compte.show');
Route::post('/suppression-compte', [SuppressionCompteController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('suppression-compte.store');
