<?php

use App\Domains\Commitments\Http\Controllers\ChainDashboardController;
use App\Domains\Commitments\Http\Controllers\ExportComptableController;
use App\Domains\Commitments\Http\Controllers\ObligationsOuvertesController;
use App\Domains\Commitments\Http\Controllers\TresorerieController;
use App\Domains\Needs\Http\Controllers\DossierChaineController;
use Illuminate\Support\Facades\Route;

Route::get('/chaine/tableau-de-bord', ChainDashboardController::class);
Route::get('/chaine/tresorerie', [TresorerieController::class, 'show']);
Route::get('/chaine/obligations', [ObligationsOuvertesController::class, 'show']);
Route::get('/chaine/comptabilite', [ExportComptableController::class, 'show']);
Route::get('/chaine/comptabilite/export', [ExportComptableController::class, 'exporter']);
Route::get('/chaine/dossier', [DossierChaineController::class, 'index']);
Route::get('/chaine/dossier/{expressionBesoin}', [DossierChaineController::class, 'show']);
