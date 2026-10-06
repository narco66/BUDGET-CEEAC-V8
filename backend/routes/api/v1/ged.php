<?php

use App\Domains\Ged\Http\Controllers\GedController;
use Illuminate\Support\Facades\Route;

Route::get('/ged/referentiel', [GedController::class, 'referentiel']);
Route::get('/ged/dossier', [GedController::class, 'dossier']);
Route::post('/ged/export', [GedController::class, 'exporter']);
Route::get('/ged', [GedController::class, 'index']);
Route::post('/ged', [GedController::class, 'store']);
Route::get('/ged/{gedDocument}', [GedController::class, 'show']);
Route::get('/ged/{gedDocument}/fichier', [GedController::class, 'download']);
Route::post('/ged/{gedDocument}/versions', [GedController::class, 'version']);
Route::post('/ged/{gedDocument}/decision', [GedController::class, 'decider']);
Route::post('/ged/{gedDocument}/tags', [GedController::class, 'etiqueter']);
