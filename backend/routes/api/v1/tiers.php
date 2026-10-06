<?php

use App\Domains\Suppliers\Http\Controllers\TiersController;
use Illuminate\Support\Facades\Route;

Route::get('/tiers', [TiersController::class, 'index']);
Route::post('/tiers', [TiersController::class, 'store']);
Route::post('/tiers/comptes/{compte}/valider', [TiersController::class, 'validateAccount']);
Route::post('/tiers/comptes/{compte}/rejeter', [TiersController::class, 'rejectAccount']);
Route::post('/tiers/comptes/{compte}/desactiver', [TiersController::class, 'deactivateAccount']);
Route::get('/tiers/{tiers}', [TiersController::class, 'show']);
Route::put('/tiers/{tiers}', [TiersController::class, 'update']);
Route::delete('/tiers/{tiers}', [TiersController::class, 'destroy']);
Route::post('/tiers/{tiers}/statut', [TiersController::class, 'changeStatus']);
Route::post('/tiers/{tiers}/comptes', [TiersController::class, 'storeAccount']);
Route::post('/tiers/{tiers}/conformite', [TiersController::class, 'storeCompliance']);
Route::post('/tiers/{tiers}/incidents', [TiersController::class, 'storeIncident']);
Route::post('/tiers/{tiers}/fusionner', [TiersController::class, 'merge']);
