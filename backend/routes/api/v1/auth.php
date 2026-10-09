<?php

use App\Shared\Auth\Http\ActorController;
use App\Shared\Auth\Http\AuthController;
use App\Shared\Navigation\Http\NavigationController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [ActorController::class, 'me']);
Route::get('/acteurs', [ActorController::class, 'index']);
Route::post('/acteurs/courant', [ActorController::class, 'switch']);
Route::get('/navigation', NavigationController::class);
