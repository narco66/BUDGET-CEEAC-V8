<?php

use App\Domains\Planning\Http\Controllers\GarPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/planification', [GarPlanController::class, 'index']);
Route::post('/planification/initialiser', [GarPlanController::class, 'initialiser']);
Route::post('/planification/versions/{version}/noeuds', [GarPlanController::class, 'storeNode'])->whereNumber('version');
Route::patch('/planification/noeuds/{node}', [GarPlanController::class, 'updateNode'])->whereNumber('node');
Route::post('/planification/noeuds/{node}/archiver', [GarPlanController::class, 'archiveNode'])->whereNumber('node');
Route::post('/planification/versions/{version}/soumettre', [GarPlanController::class, 'soumettre'])->whereNumber('version');
Route::post('/planification/versions/{version}/valider', [GarPlanController::class, 'valider'])->whereNumber('version');
Route::post('/planification/versions/{version}/publier', [GarPlanController::class, 'publier'])->whereNumber('version');
Route::post('/planification/versions/{version}/avenant', [GarPlanController::class, 'avenant'])->whereNumber('version');
