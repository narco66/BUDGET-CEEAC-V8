<?php

use App\Domains\Commitments\Http\Controllers\OrdonnancementController;
use Illuminate\Support\Facades\Route;

Route::get('/ordonnancements/export', [OrdonnancementController::class, 'export']);
Route::get('/ordonnancements/delegations', [OrdonnancementController::class, 'delegations']);
Route::post('/ordonnancements/delegations', [OrdonnancementController::class, 'storeDelegation']);
Route::post('/ordonnancements/suppleances', [OrdonnancementController::class, 'storeSuppleance']);
Route::get('/ordonnancements', [OrdonnancementController::class, 'index']);
Route::get('/ordonnancements/{ordonnancement}', [OrdonnancementController::class, 'show']);
Route::post('/ordonnancements/{ordonnancement}/fractionner', [OrdonnancementController::class, 'fractionner']);
Route::post('/ordonnancements/{ordonnancement}/signer', [OrdonnancementController::class, 'sign']);
Route::post('/ordonnancements/{ordonnancement}/retourner', [OrdonnancementController::class, 'sendBack']);
Route::post('/ordonnancements/{ordonnancement}/rejeter', [OrdonnancementController::class, 'reject']);
Route::post('/ordonnancements/{ordonnancement}/reprendre', [OrdonnancementController::class, 'reprendre']);
Route::get('/ordonnancements/{ordonnancement}/pdf', [OrdonnancementController::class, 'pdf']);
