<?php

namespace App\Shared\Notifications;

use App\Domains\Administration\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Comptes qui exercent effectivement un rôle aujourd’hui : rôle principal,
 * habilitation active, intérim en cours ou délégation administrative en
 * cours. Seuls les comptes actifs sont retenus.
 *
 * C’est la même définition que celle des droits d’action (User::holds et
 * « Mes tâches ») : quiconque peut agir est prévenu, et seulement lui.
 */
class RoleHolders
{
    /**
     * @param  string|list<string>  $roles
     * @return Builder<User>
     */
    public function query(string|array $roles): Builder
    {
        // Même règle que User::heldRoleCodes : un rôle absent du catalogue
        // reste actif, seul un rôle explicitement désactivé est écarté.
        $demandes = array_values(array_filter((array) $roles, fn ($code) => is_string($code) && $code !== ''));
        $inactifs = Role::query()->whereIn('code', $demandes)->where('active', false)->pluck('code')->all();
        $codes = array_values(array_diff($demandes, $inactifs));
        if ($codes === []) {
            return User::query()->whereRaw('1 = 0');
        }
        $today = today()->toDateString();

        return User::query()
            ->where('account_status', 'actif')
            ->where(function (Builder $query) use ($codes, $today) {
                $query->whereIn('role', $codes)
                    ->orWhereIn('id', $this->habilitations($codes, $today))
                    ->orWhereIn('id', $this->interims($codes, $today))
                    ->orWhereIn('id', $this->delegations($codes, $today));
            });
    }

    /**
     * @param  list<string>  $codes
     */
    private function habilitations(array $codes, string $today): QueryBuilder
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('roles.code', $codes)
            ->where('user_roles.status', 'active')
            ->where(fn ($q) => $q->whereNull('user_roles.starts_on')->orWhereDate('user_roles.starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('user_roles.ends_on')->orWhereDate('user_roles.ends_on', '>=', $today))
            ->select('user_roles.user_id');
    }

    /**
     * @param  list<string>  $codes
     */
    private function interims(array $codes, string $today): QueryBuilder
    {
        return DB::table('substitutions')
            ->leftJoin('users as titulaires', 'titulaires.id', '=', 'substitutions.titulaire_id')
            ->where('substitutions.status', 'active')
            ->whereDate('substitutions.starts_on', '<=', $today)
            ->whereDate('substitutions.ends_on', '>=', $today)
            ->where(fn ($q) => $q->whereIn('substitutions.fonction', $codes)->orWhereIn('titulaires.role', $codes))
            ->select('substitutions.interim_id');
    }

    /**
     * @param  list<string>  $codes
     */
    private function delegations(array $codes, string $today): QueryBuilder
    {
        return DB::table('admin_delegations')
            ->join('users as delegants', 'delegants.id', '=', 'admin_delegations.delegant_id')
            ->where('admin_delegations.status', 'active')
            ->whereDate('admin_delegations.starts_on', '<=', $today)
            ->whereDate('admin_delegations.ends_on', '>=', $today)
            ->whereIn('delegants.role', $codes)
            ->select('admin_delegations.delegataire_id');
    }
}
