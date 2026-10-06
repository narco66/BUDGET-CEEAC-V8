<?php

namespace App\Shared\Auth\Http;

use App\Domains\Administration\Models\UserSession;
use App\Domains\Administration\Services\SecurityPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Audit\AuditService;
use App\Shared\Auth\SsoClient;
use App\Shared\Auth\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public const SESSION_KEY = 'gesbudep_user_session_id';

    public function login(Request $request, SecurityPolicy $policy): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:12'],
            'secret' => ['nullable', 'string', 'max:64'],
        ]);

        $throttleKey = Str::lower($data['email']).'|'.$request->ip();
        $maxAttempts = $policy->maxFailures();
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $this->audit(null, 'auth.connexion_bloquee', $data['email'], $request, 'echec');

            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.ceil(RateLimiter::availableIn($throttleKey) / 60).' minute(s).',
            ])->status(429);
        }

        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, $policy->lockMinutes() * 60);
            $this->audit($user, 'auth.echec', $data['email'], $request, 'echec');

            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        if (in_array($user->account_status, ['suspendu', 'desactive', 'archive'], true)) {
            $this->audit($user, 'auth.compte_inactif', $data['email'], $request, 'echec');

            throw ValidationException::withMessages([
                'email' => 'Ce compte ne peut pas se connecter.',
            ])->status(403);
        }

        if ($user->locked_until?->isFuture()) {
            $this->audit($user, 'auth.compte_verrouille', $data['email'], $request, 'echec');

            throw ValidationException::withMessages([
                'email' => 'Ce compte est verrouillé.',
            ])->status(423);
        }

        $enrollment = $this->challengeMfa($user, $request, $throttleKey, $policy);
        if ($enrollment !== null) {
            return $enrollment;
        }

        RateLimiter::clear($throttleKey);
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'last_seen' => now(),
        ]);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit($user, 'auth.connexion', $data['email'], $request, 'succes');

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put(self::SESSION_KEY, $session->id);

            return response()->json(['data' => ActorController::payload($user)]);
        }

        $token = $user->createToken('api-session-'.$session->id, ['*'], now()->addMinutes((int) config('session.lifetime')));

        return response()->json([
            'data' => ActorController::payload($user),
            'token' => $token->plainTextToken,
        ]);
    }

    public function ssoDisponible(SsoClient $sso): JsonResponse
    {
        return response()->json(['actif' => $sso->configured()]);
    }

    public function ssoRedirect(Request $request, SsoClient $sso): RedirectResponse
    {
        if (! $sso->configured() || ! $request->hasSession()) {
            return redirect($this->frontend('/connexion?erreur=sso'));
        }
        $state = Str::random(40);
        $request->session()->put('sso_state', $state);

        return redirect()->away($sso->authorizationUrl($state));
    }

    public function ssoCallback(Request $request, SsoClient $sso): RedirectResponse
    {
        $expected = $request->hasSession() ? $request->session()->pull('sso_state') : null;
        $received = $request->string('state')->toString();
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $received)) {
            return redirect($this->frontend('/connexion?erreur=sso'));
        }
        try {
            $email = $sso->emailFromCode($request->string('code')->toString());
        } catch (ValidationException) {
            return redirect($this->frontend('/connexion?erreur=sso'));
        }
        $user = User::query()->where('email', $email)->first();
        if ($user === null || in_array($user->account_status, ['suspendu', 'desactive', 'archive'], true) || $user->locked_until?->isFuture()) {
            return redirect($this->frontend('/connexion?erreur=sso'));
        }
        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'last_seen' => now(),
        ]);
        $user->forceFill(['last_login_at' => now(), 'identity_source' => 'sso'])->save();
        $this->audit($user, 'auth.connexion_sso', $email, $request, 'succes');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $session->id);

        return redirect($this->frontend('/taches'));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('gesbudep.frontend_url'), '/').$path;
    }

    private function challengeMfa(User $user, Request $request, string $throttleKey, SecurityPolicy $policy): ?JsonResponse
    {
        if (! $user->mfa_required) {
            return null;
        }

        $code = $request->string('code')->toString();
        $stored = $user->totp_secret;
        if (is_string($stored) && $stored !== '') {
            if (! Totp::verify($stored, $code)) {
                RateLimiter::hit($throttleKey, $policy->lockMinutes() * 60);
                $this->audit($user, 'auth.mfa_echec', $user->email, $request, 'echec');

                throw ValidationException::withMessages([
                    'code' => 'Code d’authentification requis.',
                ]);
            }

            return null;
        }

        $pending = $request->string('secret')->toString();
        if ($pending !== '' && Totp::verify($pending, $code)) {
            $user->forceFill(['totp_secret' => $pending])->save();

            return null;
        }

        return response()->json([
            'mfa' => 'enrolement',
            'secret' => $pending !== '' ? $pending : Totp::secret(),
        ], 202);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            $sessionId = $request->session()->get(self::SESSION_KEY);
            if ($sessionId !== null) {
                UserSession::query()->whereKey($sessionId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        if ($user instanceof User) {
            $this->audit($user, 'auth.deconnexion', $user->email, $request, 'succes');
        }

        return response()->json(['ok' => true]);
    }

    public function demanderReinitialisation(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $user = User::query()->where('email', $data['email'])->first();
        $eligible = $user !== null && ! in_array($user->account_status, ['suspendu', 'desactive', 'archive'], true);
        if ($eligible) {
            $status = Password::sendResetLink(['email' => $user->email]);
            if ($status === Password::RESET_THROTTLED) {
                throw ValidationException::withMessages([
                    'email' => 'Trop de demandes. Réessayez dans quelques minutes.',
                ])->status(429);
            }
            $this->audit($user, 'auth.reinitialisation_demandee', $user->email, $request, 'succes');
        }

        return response()->json([
            'message' => 'Si ce compte est actif, un message de réinitialisation a été envoyé.',
        ]);
    }

    public function reinitialiser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
        ]);
        $status = Password::reset(
            $data,
            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
                UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $this->audit($user, 'auth.mot_de_passe_reinitialise', $user->email, $request, 'succes');
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Lien invalide ou expiré.']);
        }

        return response()->json(['message' => 'Le mot de passe a été réinitialisé.']);
    }

    private function audit(?User $user, string $action, string $email, Request $request, string $result): void
    {
        app(AuditService::class)->enregistrer(
            $user,
            $action,
            'user',
            Str::limit((string) ($user?->id ?? $email), 64, ''),
            null,
            null,
            null,
            $result,
        );
    }
}
