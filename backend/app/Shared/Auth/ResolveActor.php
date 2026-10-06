<?php

namespace App\Shared\Auth;

use App\Domains\Administration\Models\UserSession;
use App\Models\User;
use App\Shared\Auth\Http\AuthController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * S’exécute après auth:sanctum : contrôle l’état du compte et de la session
 * de l’utilisateur authentifié, puis applique, en démonstration uniquement,
 * le changement d’acteur par en-tête X-Actor-Id.
 */
class ResolveActor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->assertAccountUsable($user);
        $this->assertSessionNotRevoked($request);

        $actorId = $request->headers->get('X-Actor-Id');
        if ($actorId !== null && config('gesbudep.demo_impersonation') === true && (string) $user->id !== $actorId) {
            $actor = User::query()->find($actorId);
            abort_unless($actor instanceof User, 401, 'Acteur de démonstration inconnu.');
            $this->assertAccountUsable($actor);
            Auth::setUser($actor);
            $request->setUserResolver(fn () => $actor);
        }

        return $next($request);
    }

    private function assertAccountUsable(User $user): void
    {
        if (in_array($user->account_status, ['suspendu', 'desactive', 'archive'], true)) {
            abort(401, 'Ce compte ne peut pas se connecter.');
        }
        if ($user->locked_until?->isFuture()) {
            abort(423, 'Ce compte est verrouillé.');
        }
    }

    private function assertSessionNotRevoked(Request $request): void
    {
        $sessionId = $this->trackedSessionId($request);
        if ($sessionId === null) {
            return;
        }

        $session = UserSession::query()->find($sessionId);
        if ($session === null || $session->revoked_at !== null) {
            $token = $request->user()?->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }
            abort(401, 'Cette session a été révoquée.');
        }

        if ($session->last_seen === null || $session->last_seen->lt(now()->subMinute())) {
            $session->forceFill(['last_seen' => now()])->save();
        }
    }

    private function trackedSessionId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && str_starts_with((string) $token->name, 'api-session-')) {
            return (int) substr($token->name, strlen('api-session-'));
        }

        if ($request->hasSession()) {
            $id = $request->session()->get(AuthController::SESSION_KEY);

            return $id !== null ? (int) $id : null;
        }

        return null;
    }
}
