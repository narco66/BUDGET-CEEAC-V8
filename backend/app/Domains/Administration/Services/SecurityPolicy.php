<?php

namespace App\Domains\Administration\Services;

use Illuminate\Support\Facades\DB;

/**
 * Politique de sécurité paramétrée (table security_policies).
 * Les valeurs de config/gesbudep.php servent de repli tant qu’aucune
 * politique n’est enregistrée.
 */
class SecurityPolicy
{
    private ?object $row = null;

    private bool $loaded = false;

    public function maxFailures(): int
    {
        return $this->positive('max_failures', (int) config('gesbudep.login.max_attempts', 5));
    }

    public function lockMinutes(): int
    {
        return $this->positive('lock_minutes', (int) config('gesbudep.login.lockout_minutes', 15));
    }

    public function minPasswordLength(): int
    {
        return max(8, $this->positive('min_length', 8));
    }

    /**
     * Rôles pour lesquels la MFA est demandée. Enregistré, non encore appliqué :
     * aucune authentification forte n’est disponible dans l’application.
     *
     * @return list<string>
     */
    public function mfaRoles(): array
    {
        $roles = json_decode((string) ($this->policy()?->mfa_roles ?? '[]'), true);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    private function positive(string $column, int $fallback): int
    {
        $value = $this->policy()?->{$column} ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    private function policy(): ?object
    {
        if (! $this->loaded) {
            $this->row = DB::table('security_policies')->orderBy('id')->first();
            $this->loaded = true;
        }

        return $this->row;
    }
}
