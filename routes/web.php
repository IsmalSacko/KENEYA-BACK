<?php

declare(strict_types=1);

use App\Http\Controllers\Web\SuppressionCompteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Page publique de demande de suppression de compte / de données (RGPD, Play Store).
Route::get('/suppression-compte', [SuppressionCompteController::class, 'show'])
    ->name('suppression-compte.show');
Route::post('/suppression-compte', [SuppressionCompteController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('suppression-compte.store');
