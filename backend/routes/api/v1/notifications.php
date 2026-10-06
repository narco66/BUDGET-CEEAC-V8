<?php

use App\Shared\Notifications\Http\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/notifications/compteur', [NotificationController::class, 'compteur']);
Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
Route::put('/notifications/preferences', [NotificationController::class, 'enregistrerPreferences']);
Route::post('/notifications/lues', [NotificationController::class, 'toutLire']);
Route::get('/notifications', [NotificationController::class, 'index']);
Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
Route::post('/notifications/{notification}/ouvrir', [NotificationController::class, 'ouvrir']);
Route::post('/notifications/{notification}/lire', [NotificationController::class, 'lire']);
Route::post('/notifications/{notification}/non-lue', [NotificationController::class, 'nonLue']);
