<?php

use App\Shared\Auth\Http\AuthController;
use App\Shared\Auth\ResolveActor;
use App\Shared\Documents\Http\DocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/mot-de-passe/oublie', [AuthController::class, 'demanderReinitialisation'])->middleware('throttle:6,1');
    Route::post('/auth/mot-de-passe/reinitialiser', [AuthController::class, 'reinitialiser'])->middleware('throttle:6,1');
    Route::get('/auth/sso', [AuthController::class, 'ssoDisponible']);
    Route::middleware('web')->group(function (): void {
        Route::get('/auth/sso/rediriger', [AuthController::class, 'ssoRedirect']);
        Route::get('/auth/sso/callback', [AuthController::class, 'ssoCallback']);
    });

    Route::get('/public/documents/{code}', [DocumentController::class, 'verifyPublic'])->middleware('throttle:30,1');

    Route::middleware(['auth:sanctum', ResolveActor::class])->group(function (): void {
        require __DIR__.'/api/v1/auth.php';
        require __DIR__.'/api/v1/budget.php';
        require __DIR__.'/api/v1/needs.php';
        require __DIR__.'/api/v1/engagements.php';
        require __DIR__.'/api/v1/liquidations.php';
        require __DIR__.'/api/v1/ordonnancements.php';
        require __DIR__.'/api/v1/paiements.php';
        require __DIR__.'/api/v1/admin.php';
        require __DIR__.'/api/v1/organisation.php';
        require __DIR__.'/api/v1/taches.php';
        require __DIR__.'/api/v1/notifications.php';
        require __DIR__.'/api/v1/chaine.php';
        require __DIR__.'/api/v1/documents.php';
        require __DIR__.'/api/v1/ged.php';
        require __DIR__.'/api/v1/audit.php';
        require __DIR__.'/api/v1/tiers.php';
        require __DIR__.'/api/v1/monitoring.php';
        require __DIR__.'/api/v1/planification.php';
        require __DIR__.'/api/v1/marches.php';
        require __DIR__.'/api/v1/recettes.php';
    });
});
