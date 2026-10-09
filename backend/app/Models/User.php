<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Services\HabilitationCatalogue;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Services\OrganizationScopeService;
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
use Illuminate\Support\Collection;
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
    /**
     * Les fonctions officielles du référentiel organisationnel sont reliées à
     * leur rôle applicatif historique afin qu’un titulaire puisse agir sans
     * devoir cumuler deux rôles techniques.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_ALIASES = [
        'president' => ['president', 'ordonnateur'],
        'agent_comptable_central' => ['agent_comptable_central', 'agent_comptable'],
        'auditeur_interne' => ['auditeur_interne', 'auditeur'],
        'controleur_financier_central' => ['controleur_financier_central', 'controleur_financier'],
    ];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string>|null */
    private ?array $heldRoleCodesCache = null;

    private ?string $heldRoleStamp = null;

    private ?bool $cataloguePoseCache = null;

    /** @var array<string, Collection<int, mixed>> */
    private array $niveauxPermissionCache = [];

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

        $pose = $this->cataloguePoseCache ??= SystemSetting::query()
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

        // Niveaux lus une fois par permission et par requête : une liste qui
        // évalue la même permission sur chaque rangée ne relit pas la matrice.
        $niveaux = $this->niveauxPermissionCache[$permission] ??= DB::table('role_permission')
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
        if (in_array('super_admin', $held, true)) {
            return true;
        }
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

        $codes = $this->withAliases($codes);

        if (in_array('super_admin', $codes, true)) {
            $codes = array_merge($codes, Role::query()->pluck('code')->all());
        }

        return $this->heldRoleCodesCache = array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function withAliases(array $codes): array
    {
        foreach ($codes as $code) {
            foreach (self::ROLE_ALIASES[$code] ?? [] as $alias) {
                $codes[] = $alias;
            }
        }

        return $codes;
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

        return $this->organizationScopeCache = app(OrganizationScopeService::class)->forUser($this);
    }

    public function seesOrganization(?int $unitId): bool
    {
        if ($unitId === null) {
            return false;
        }

        $ids = $this->organizationScopeIds();
        if ($ids === null) {
            return true;
        }

        return in_array($unitId, $ids, true);
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
     * Même périmètre que restrictOrganization, appliqué à travers une relation
     * (ex. « expressionBesoin » pour un engagement) : une liste ne montre que ce
     * que la fiche accepterait d’ouvrir.
     *
     * @param  Builder<*>  $query
     */
    public function restrictOrganizationThrough(Builder $query, string $relation, string $column = 'organization_unit_id'): void
    {
        $ids = $this->organizationScopeIds();
        if ($ids === null) {
            return;
        }
        $query->whereHas($relation, fn (Builder $inner) => $inner->whereIn($column, $ids));
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
