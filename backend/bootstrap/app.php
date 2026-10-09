<?php

use App\Http\Middleware\EnsureDailyMonitoring;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi();
        $middleware->appendToGroup('api', EnsureDailyMonitoring::class);
        // Pas de route nommée « login » : l’API répond 401 en JSON, l’interface renvoie vers la page de connexion du SPA.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/connexion');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
                'code' => 'VALIDATION_ERROR',
            ], $exception->status);
        });

        // Erreurs de l’API : un code stable et un message en français, sans nom de
        // classe, de table ni de trace. Un message métier explicite (abort(403, '…'))
        // est conservé ; les messages techniques par défaut sont remplacés.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || $exception instanceof ValidationException) {
                return null;
            }
            $exception = match (true) {
                $exception instanceof ModelNotFoundException => new NotFoundHttpException('', $exception),
                $exception instanceof AuthorizationException => new AccessDeniedHttpException($exception->getMessage(), $exception),
                default => $exception,
            };
            [$status, $code, $defaut] = match (true) {
                $exception instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Votre session a expiré ou vous n’êtes pas connecté.'],
                $exception instanceof HttpExceptionInterface => match ($exception->getStatusCode()) {
                    400 => [400, 'BAD_REQUEST', 'La requête est invalide.'],
                    403 => [403, 'FORBIDDEN', 'Vous n’êtes pas autorisé à effectuer cette action.'],
                    404 => [404, 'NOT_FOUND', 'La ressource demandée est introuvable.'],
                    405 => [405, 'METHOD_NOT_ALLOWED', 'Cette opération n’est pas permise sur cette ressource.'],
                    409 => [409, 'CONFLICT', 'L’opération entre en conflit avec l’état actuel du dossier.'],
                    419 => [419, 'SESSION_EXPIRED', 'Votre session a expiré. Reconnectez-vous.'],
                    429 => [429, 'TOO_MANY_REQUESTS', 'Trop de requêtes. Patientez quelques instants avant de réessayer.'],
                    default => [$exception->getStatusCode(), 'HTTP_ERROR', 'La requête n’a pas pu aboutir.'],
                },
                default => [500, 'SERVER_ERROR', 'Une erreur interne est survenue. Réessayez ; si elle persiste, signalez-la à l’administrateur.'],
            };
            $message = $exception->getMessage();
            $technique = $message === ''
                || $status >= 500
                || preg_match('/^(no query results|this action is unauthorized|unauthenticated|too many attempts|forbidden|not found|the route|method not allowed|csrf token mismatch|unauthorized|server error)|is not supported for route|could not be found/i', $message) === 1;
            if ($status >= 500 && config('app.debug')) {
                return null;
            }
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json([
                'message' => $technique ? $defaut : $message,
                'code' => $code,
            ], $status, $headers);
        });
    })->create();
