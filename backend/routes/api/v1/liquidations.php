<?php

use App\Domains\Commitments\Http\Controllers\LiquidationController;
use Illuminate\Support\Facades\Route;

Route::get('/liquidations/export', [LiquidationController::class, 'export']);
Route::get('/liquidations', [LiquidationController::class, 'index']);
Route::post('/engagements/{engagement}/liquidations', [LiquidationController::class, 'openNext']);
Route::get('/liquidations/{liquidation}', [LiquidationController::class, 'show']);
Route::post('/liquidations/{liquidation}/certifier', [LiquidationController::class, 'certify']);
Route::post('/liquidations/{liquidation}/facture', [LiquidationController::class, 'invoice']);
Route::post('/liquidations/{liquidation}/soumettre', [LiquidationController::class, 'submit']);
Route::post('/liquidations/{liquidation}/demande-doublon', [LiquidationController::class, 'requestDuplicate']);
Route::post('/liquidations/{liquidation}/retourner', [LiquidationController::class, 'sendBack']);
Route::post('/liquidations/{liquidation}/complement', [LiquidationController::class, 'complement']);
Route::post('/liquidations/{liquidation}/rejeter', [LiquidationController::class, 'reject']);
Route::post('/liquidations/{liquidation}/viser', [LiquidationController::class, 'vise']);
Route::post('/liquidations/{liquidation}/rectifier', [LiquidationController::class, 'rectify']);
Route::get('/liquidations/{liquidation}/pdf', [LiquidationController::class, 'pdf']);
