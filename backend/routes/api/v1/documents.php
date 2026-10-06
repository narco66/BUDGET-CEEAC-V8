<?php

use App\Shared\Documents\Http\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/documents/recherche', [DocumentController::class, 'search']);
Route::post('/documents/conservation', [DocumentController::class, 'retain']);
Route::get('/documents', [DocumentController::class, 'index']);
Route::get('/documents/verifier/{code}', [DocumentController::class, 'verify']);
