<?php

use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/taches/compteur', [TaskController::class, 'count']);
Route::get('/taches', [TaskController::class, 'index']);
Route::get('/taches/{tache}', [TaskController::class, 'show']);
Route::post('/taches/{tache}/prendre', [TaskController::class, 'start']);
Route::post('/taches/{tache}/liberer', [TaskController::class, 'release']);
Route::post('/taches/{tache}/commentaires', [TaskController::class, 'comment']);
