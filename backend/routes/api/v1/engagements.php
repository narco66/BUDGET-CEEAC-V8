<?php

use App\Domains\Commitments\Http\Controllers\EngagementController;
use Illuminate\Support\Facades\Route;

Route::get('/engagements/export', [EngagementController::class, 'export']);
Route::get('/engagements', [EngagementController::class, 'index']);
Route::get('/engagements/{engagement}', [EngagementController::class, 'show']);
Route::patch('/engagements/{engagement}', [EngagementController::class, 'update']);
Route::post('/engagements/{engagement}/transmettre', [EngagementController::class, 'transmit']);
Route::post('/engagements/{engagement}/retourner', [EngagementController::class, 'sendBack']);
Route::post('/engagements/{engagement}/rejeter', [EngagementController::class, 'reject']);
Route::post('/engagements/{engagement}/viser', [EngagementController::class, 'vise']);
Route::post('/engagements/{engagement}/degager', [EngagementController::class, 'degager']);
Route::post('/engagements/{engagement}/partiel', [EngagementController::class, 'partiel']);
Route::post('/engagements/{engagement}/avenant', [EngagementController::class, 'avenant']);
Route::post('/engagements/{engagement}/annuler', [EngagementController::class, 'annuler']);
Route::post('/engagements/{engagement}/pieces', [EngagementController::class, 'storePiece']);
Route::get('/engagements/{engagement}/pdf', [EngagementController::class, 'pdf']);
