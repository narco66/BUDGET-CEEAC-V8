<?php

namespace App\Shared\Auth;

use App\Domains\Administration\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Réauthentification du signataire pour les actes critiques (CDC §40, §55) :
 * la signature n’est acceptée que si l’acteur ressaisit son mot de passe.
 * Les échecs sont journalisés et limités.
 */
class SignatureVerifier
{
    public function verify(User $actor, string $password, string $act, string $objectId): void
    {
        $key = 'signature|'.$actor->id;
        $maxAttempts = (int) config('gesbudep.login.max_attempts');
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'mot_de_passe' => 'Trop de tentatives de signature. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ])->status(429);
        }

        if (! Hash::check($password, (string) $actor->password)) {
            RateLimiter::hit($key, (int) config('gesbudep.login.lockout_minutes') * 60);
            AuditEvent::query()->create([
                'actor_id' => $actor->id,
                'role' => $actor->role,
                'action' => 'signature.echec',
                'object_type' => $act,
                'object_id' => $objectId,
                'result' => 'echec',
                'ip' => request()->ip(),
            ]);

            throw ValidationException::withMessages([
                'mot_de_passe' => 'Mot de passe incorrect : la signature est refusée.',
            ]);
        }

        RateLimiter::clear($key);
    }
}
