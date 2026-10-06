<?php

use App\Domains\Commitments\Http\Controllers\ChainDashboardController;
use App\Domains\Commitments\Http\Controllers\PaiementController;
use Illuminate\Support\Facades\Route;

Route::get('/paiements/export', [PaiementController::class, 'export']);
Route::get('/paiements/rapprochements', [ChainDashboardController::class, 'reconciliations']);
Route::get('/paiements/lots', [PaiementController::class, 'lots']);
Route::post('/paiements/lots', [PaiementController::class, 'storeLot']);
Route::post('/paiements/lots/{lot}/executer', [PaiementController::class, 'executerLot']);
Route::get('/paiements', [PaiementController::class, 'index']);
Route::get('/paiements/{paiement}', [PaiementController::class, 'show']);
Route::post('/paiements/{paiement}/prendre-en-charge', [PaiementController::class, 'prendreEnCharge']);
Route::post('/paiements/{paiement}/preparer', [PaiementController::class, 'preparer']);
Route::get('/paiements/{paiement}/comptes-eligibles', [PaiementController::class, 'eligibleAccounts']);
Route::post('/paiements/{paiement}/soumettre', [PaiementController::class, 'soumettre']);
Route::post('/paiements/{paiement}/valider', [PaiementController::class, 'valider']);
Route::post('/paiements/{paiement}/signer', [PaiementController::class, 'signer']);
Route::post('/paiements/{paiement}/retourner', [PaiementController::class, 'retourner']);
Route::post('/paiements/{paiement}/rejeter', [PaiementController::class, 'rejeter']);
Route::post('/paiements/{paiement}/suspendre', [PaiementController::class, 'suspendre']);
Route::post('/paiements/{paiement}/executer', [PaiementController::class, 'executer']);
Route::post('/paiements/{paiement}/rapprocher', [PaiementController::class, 'rapprocher']);
Route::post('/paiements/{paiement}/rejet-bancaire', [PaiementController::class, 'rejetBancaire']);
Route::post('/paiements/{paiement}/reemettre', [PaiementController::class, 'reemettre']);
Route::post('/paiements/{paiement}/lever-suspension', [PaiementController::class, 'leverSuspension']);
Route::get('/paiements/{paiement}/pdf', [PaiementController::class, 'pdf']);
