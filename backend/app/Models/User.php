<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Services\HabilitationCatalogue;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Shared\Auth\Notifications\ReinitialisationMotDePasse;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'organization_unit_id', 'function_title', 'role', 'initials',
    'uuid', 'matricule', 'phone', 'account_status', 'locale', 'timezone', 'last_login_at',
    'password_changed_at', 'mfa_required', 'identity_source', 'locked_until', 'deactivated_at',
    'notifications_courriel',
])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string>|null */
    private ?array $heldRoleCodesCache = null;

    private ?string $heldRoleStamp = null;

    private bool $organizationScopeResolved = false;

    /** @var list<int>|null */
    private ?array $organizationScopeCache = null;

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ReinitialisationMotDePasse($token));
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function expressionBesoins(): HasMany
    {
        return $this->hasMany(ExpressionBesoin::class, 'initiator_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['status', 'starts_on', 'ends_on', 'motif', 'granted_by', 'decision', 'plafond_fcfa', 'origine', 'scope_unit_id', 'derogation'])
            ->withTimestamps();
    }

    /**
     * Permission appliquée : un rôle détenu porte cette permission active.
     * Le niveau « global » ignore le périmètre. Le niveau « scoped » le respecte
     * lorsqu’une unité est fournie. Tant que le catalogue n’est pas posé, le rôle historique reste la référence.
     */
    public function porte(string $permission, ?int $organizationUnitId = null): bool
    {
        if ($this->account_status !== 'actif') {
            return false;
        }

        $pose = SystemSetting::query()
            ->where('key', 'habilitations.catalogue_initial')
            ->exists();
        if (! $pose) {
            $defauts = HabilitationCatalogue::rolesParDefaut($permission);

            return $defauts !== [] && $this->holds(...$defauts);
        }

        $tenus = $this->heldRoleCodes();
        if ($tenus === []) {
            return false;
        }

        $niveaux = DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('permissions.code', $permission)
            ->where('permissions.active', true)
            ->where('roles.active', true)
            ->whereIn('roles.code', $tenus)
            ->pluck('role_permission.level');
        $utiles = $niveaux->filter(fn (mixed $niveau): bool => $niveau !== 'denied' && $niveau !== null && $niveau !== '');
        if ($utiles->isEmpty()) {
            return false;
        }
        if ($organizationUnitId !== null && ! $utiles->contains('global') && ! $this->seesOrganization($organizationUnitId)) {
            return false;
        }

        return true;
    }

    /** Plafond le plus bas parmi les habilitations actives, ou null s’il n’y en a pas. */
    public function plafondActif(): ?int
    {
        $valeur = DB::table('user_roles')
            ->where('user_id', $this->id)
            ->where('status', 'active')
            ->whereNotNull('plafond_fcfa')
            ->min('plafond_fcfa');

        return $valeur === null ? null : (int) $valeur;
    }

    public function holds(string ...$roles): bool
    {
        if ($roles === []) {
            return false;
        }
        $held = $this->heldRoleCodes();
        foreach ($roles as $role) {
            if ($role !== '' && in_array($role, $held, true)) {
                return true;
            }
        }

        return false;
    }

    public function holdsAny(): bool
    {
        return $this->heldRoleCodes() !== [];
    }

    /**
     * Rôle principal, rôles attribués et intérims en cours.
     *
     * @return list<string>
     */
    public function heldRoleCodes(): array
    {
        $stamp = (string) $this->role;
        if ($this->heldRoleCodesCache !== null && $this->heldRoleStamp === $stamp) {
            return $this->heldRoleCodesCache;
        }
        $this->heldRoleStamp = $stamp;

        $codes = [];
        if (is_string($this->role) && $this->role !== '' && $this->rolePrincipalActif()) {
            $codes[] = $this->role;
        }
        $today = now()->toDateString();
        $codes = array_merge(
            $codes,
            $this->roles()
                ->where('roles.active', true)
                ->wherePivot('status', 'active')
                ->where(function ($query) use ($today) {
                    $query->whereNull('user_roles.starts_on')->orWhereDate('user_roles.starts_on', '<=', $today);
                })
                ->where(function ($query) use ($today) {
                    $query->whereNull('user_roles.ends_on')->orWhereDate('user_roles.ends_on', '>=', $today);
                })
                ->pluck('code')
                ->all(),
        );

        $substitutions = DB::table('substitutions')
            ->where('interim_id', $this->id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->get(['fonction', 'titulaire_id']);
        foreach ($substitutions as $substitution) {
            if (is_string($substitution->fonction) && $this->codeRoleActif($substitution->fonction)) {
                $codes[] = $substitution->fonction;
            }
        }
        $titulaireIds = $substitutions->pluck('titulaire_id')->filter()->unique()->all();
        if ($titulaireIds !== []) {
            $titulaireRoles = self::query()->whereIn('id', $titulaireIds)->pluck('role')->filter();
            foreach ($titulaireRoles as $code) {
                if ($this->codeRoleActif((string) $code)) {
                    $codes[] = $code;
                }
            }
        }

        return $this->heldRoleCodesCache = array_values(array_unique($codes));
    }

    /**
     * @return list<int>|null null lorsqu’aucun périmètre n’est défini
     */
    public function organizationScopeIds(): ?array
    {
        if ($this->organizationScopeResolved) {
            return $this->organizationScopeCache;
        }
        $this->organizationScopeResolved = true;
        $values = DB::table('access_scopes')
            ->where('user_id', $this->id)
            ->where('scope_type', 'organization_unit')
            ->pluck('scope_value');
        if ($values->isEmpty()) {
            return $this->organizationScopeCache = null;
        }

        return $this->organizationScopeCache = $values
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public function seesOrganization(?int $unitId): bool
    {
        $ids = $this->organizationScopeIds();
        if ($ids === null) {
            return true;
        }

        return $unitId !== null && in_array($unitId, $ids, true);
    }

    private function rolePrincipalActif(): bool
    {
        return $this->codeRoleActif((string) $this->role);
    }

    private function codeRoleActif(string $code): bool
    {
        if ($code === '') {
            return false;
        }
        $active = Role::query()->where('code', $code)->value('active');

        return $active === null || (bool) $active;
    }

    /**
     * @param  Builder<*>  $query
     */
    public function restrictOrganization(Builder $query, string $column): void
    {
        $ids = $this->organizationScopeIds();
        if ($ids === null) {
            return;
        }
        $query->whereIn($column, $ids);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_required' => 'boolean',
            'totp_secret' => 'encrypted',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }
}
