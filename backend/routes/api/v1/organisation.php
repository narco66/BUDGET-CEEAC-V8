<?php

use App\Domains\Organization\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('/organisation/arbre', [OrganizationController::class, 'arbre']);
Route::get('/organisation/unites', [OrganizationController::class, 'unites']);
Route::post('/organisation/unites', [OrganizationController::class, 'store']);
Route::get('/organisation/unites/{unit}', [OrganizationController::class, 'show']);
Route::patch('/organisation/unites/{unit}', [OrganizationController::class, 'update']);
Route::delete('/organisation/unites/{unit}', [OrganizationController::class, 'destroy']);
Route::post('/organisation/unites/{unit}/activer', [OrganizationController::class, 'activer']);
Route::post('/organisation/unites/{unit}/desactiver', [OrganizationController::class, 'desactiver']);
Route::get('/organisation/unites/{unit}/enfants', [OrganizationController::class, 'enfants']);
Route::get('/organisation/unites/{unit}/ancetres', [OrganizationController::class, 'ancetres']);
Route::get('/organisation/unites/{unit}/responsables', [OrganizationController::class, 'responsables']);
Route::get('/organisation/fonctions', [OrganizationController::class, 'fonctions']);
Route::post('/organisation/fonctions', [OrganizationController::class, 'storeFonction']);
Route::patch('/organisation/fonctions/{position}', [OrganizationController::class, 'updateFonction']);
Route::post('/organisation/affectations', [OrganizationController::class, 'storeAffectation']);
Route::post('/organisation/affectations/{assignment}/cloturer', [OrganizationController::class, 'cloturerAffectation']);
Route::get('/organisation/versions', [OrganizationController::class, 'versions']);
