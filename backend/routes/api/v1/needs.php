<?php

use App\Domains\Needs\Http\Controllers\ExpressionBesoinController;
use Illuminate\Support\Facades\Route;

Route::get('/expressions-besoin/export', [ExpressionBesoinController::class, 'export']);
Route::get('/expressions-besoin', [ExpressionBesoinController::class, 'index']);
Route::post('/expressions-besoin', [ExpressionBesoinController::class, 'store']);
Route::get('/expressions-besoin/{expressionBesoin}', [ExpressionBesoinController::class, 'show']);
Route::patch('/expressions-besoin/{expressionBesoin}', [ExpressionBesoinController::class, 'update']);
Route::post('/expressions-besoin/{expressionBesoin}/soumettre', [ExpressionBesoinController::class, 'submit']);
Route::post('/expressions-besoin/{expressionBesoin}/valider', [ExpressionBesoinController::class, 'validateStep']);
Route::post('/expressions-besoin/{expressionBesoin}/retourner', [ExpressionBesoinController::class, 'returnForCorrection']);
Route::post('/expressions-besoin/{expressionBesoin}/rejeter', [ExpressionBesoinController::class, 'reject']);
Route::post('/expressions-besoin/{expressionBesoin}/approuver', [ExpressionBesoinController::class, 'approve']);
Route::post('/expressions-besoin/{expressionBesoin}/transformer', [ExpressionBesoinController::class, 'transform']);
Route::post('/expressions-besoin/{expressionBesoin}/annuler', [ExpressionBesoinController::class, 'cancel']);
Route::post('/expressions-besoin/{expressionBesoin}/dupliquer', [ExpressionBesoinController::class, 'duplicate']);
Route::post('/expressions-besoin/{expressionBesoin}/documents', [ExpressionBesoinController::class, 'storeDocument']);
Route::get('/expressions-besoin/{expressionBesoin}/pdf', [ExpressionBesoinController::class, 'pdf']);
Route::get('/expressions-besoin/{expressionBesoin}/apercu', [ExpressionBesoinController::class, 'apercu']);
