<?php

namespace App\Shared\Auth;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Client OpenID Connect (code d’autorisation) sans dépendance supplémentaire.
 */
class SsoClient
{
    public function configured(): bool
    {
        $sso = config('gesbudep.sso');

        return ($sso['enabled'] ?? false) === true
            && is_string($sso['issuer'] ?? null) && $sso['issuer'] !== ''
            && is_string($sso['client_id'] ?? null) && $sso['client_id'] !== ''
            && is_string($sso['client_secret'] ?? null) && $sso['client_secret'] !== ''
            && is_string($sso['redirect'] ?? null) && $sso['redirect'] !== '';
    }

    public function authorizationUrl(string $state): string
    {
        $discovery = $this->discovery();
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('gesbudep.sso.client_id'),
            'redirect_uri' => config('gesbudep.sso.redirect'),
            'scope' => 'openid email',
            'state' => $state,
        ]);

        return $discovery['authorization_endpoint'].'?'.$query;
    }

    public function emailFromCode(string $code): string
    {
        $discovery = $this->discovery();
        $token = Http::asForm()->acceptJson()->post($discovery['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('gesbudep.sso.redirect'),
            'client_id' => config('gesbudep.sso.client_id'),
            'client_secret' => config('gesbudep.sso.client_secret'),
        ])->throw()->json();
        $access = $token['access_token'] ?? null;
        if (! is_string($access) || $access === '') {
            throw ValidationException::withMessages(['sso' => 'Le fournisseur d’identité n’a pas renvoyé de jeton.']);
        }
        $profile = Http::withToken($access)->acceptJson()->get($discovery['userinfo_endpoint'])->throw()->json();
        $email = $profile['email'] ?? null;
        if (! is_string($email) || $email === '') {
            throw ValidationException::withMessages(['sso' => 'Le profil institutionnel ne contient pas d’adresse électronique.']);
        }

        return $email;
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    private function discovery(): array
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['sso' => 'La connexion institutionnelle n’est pas configurée.']);
        }
        try {
            $document = Http::acceptJson()
                ->get(rtrim((string) config('gesbudep.sso.issuer'), '/').'/.well-known/openid-configuration')
                ->throw()
                ->json();
        } catch (RequestException) {
            throw ValidationException::withMessages(['sso' => 'Le fournisseur d’identité est injoignable.']);
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            if (! is_string($document[$key] ?? null) || $document[$key] === '') {
                throw ValidationException::withMessages(['sso' => 'La découverte OpenID est incomplète.']);
            }
        }

        return $document;
    }
}
