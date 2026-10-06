<?php

use App\Domains\Procurement\Http\Controllers\MarcheController;
use Illuminate\Support\Facades\Route;

Route::get('/marches', [MarcheController::class, 'index']);
Route::post('/marches', [MarcheController::class, 'store']);
Route::get('/marches/{marche}', [MarcheController::class, 'show']);
Route::put('/marches/{marche}', [MarcheController::class, 'update']);
Route::delete('/marches/{marche}', [MarcheController::class, 'destroy']);
Route::post('/marches/{marche}/statut', [MarcheController::class, 'changeStatus']);
Route::post('/marches/{marche}/rattacher', [MarcheController::class, 'attach']);
