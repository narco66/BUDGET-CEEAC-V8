<?php

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Models\AdminDelegation;
use App\Domains\Administration\Models\AuthorizationThreshold;
use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Administration\Models\Integration;
use App\Domains\Administration\Models\NumberSequence;
use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SettingVersion;
use App\Domains\Administration\Models\SodRule;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Models\UserSession;
use App\Domains\Administration\Models\WorkflowDefinition;
use App\Domains\Administration\Models\WorkflowVersion;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Models\User;
use App\Shared\Audit\AuditService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministrationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createUser(User $actor, array $data): User
    {
        $this->assertCompatible((string) $data['role'], null);
        $user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($data['prenom'].' '.$data['nom']),
            'email' => $data['email'],
            'password' => $data['password'],
            'organization_unit_id' => $data['organization_unit_id'],
            'function_title' => $data['fonction'],
            'role' => $data['role'],
            'initials' => $data['initiales'],
            'matricule' => $data['matricule'] ?? null,
            'phone' => $data['telephone'] ?? null,
            'account_status' => 'actif',
            'mfa_required' => (bool) ($data['mfa_required'] ?? false),
            'identity_source' => 'local',
            'password_changed_at' => now(),
        ]);
        $role = Role::query()->where('code', $data['role'])->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->audit($actor, 'utilisateur.creer', 'user', (string) $user->id, null, [
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return $user;
    }

    public function deactivate(User $actor, User $user, string $motif): User
    {
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['motif' => 'Un administrateur ne désactive pas son propre compte.']);
        }
        $this->protegerDernierAdministrateur($user);
        $before = $user->account_status;
        $user->forceFill([
            'account_status' => 'desactive',
            'deactivated_at' => now(),
        ])->save();
        $user->tokens()->delete();
        $this->audit($actor, 'utilisateur.desactiver', 'user', (string) $user->id, ['statut' => $before], ['statut' => 'desactive'], $motif);

        return $user;
    }

    public function assignRole(User $actor, User $user, string $roleCode): User
    {
        if ($actor->id === $user->id) {
            $this->audit($actor, 'role.autoelevation_refusee', 'user', (string) $user->id, ['role' => $user->role], ['role_demande' => $roleCode]);
            throw ValidationException::withMessages(['role' => 'Un administrateur n’ajoute pas un rôle à son propre compte.']);
        }
        $this->assertCompatible((string) $user->role, $roleCode);
        $role = Role::query()->where('code', $roleCode)->where('active', true)->first();
        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'Ce rôle n’existe pas ou n’est pas actif.']);
        }
        $pivot = [
            'status' => 'active',
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'motif' => null,
            'granted_by' => $actor->id,
            'decision' => null,
        ];
        if ($user->roles()->whereKey($role->id)->exists()) {
            $user->roles()->updateExistingPivot($role->id, $pivot);
        } else {
            $user->roles()->attach($role->id, $pivot);
        }
        $this->audit($actor, 'role.attribuer', 'user', (string) $user->id, ['role' => $user->role], ['role_ajoute' => $roleCode]);

        return $user->fresh();
    }

    public function retirerRole(User $actor, User $user, string $roleCode): User
    {
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['role' => 'Un administrateur ne retire pas un rôle de son propre compte.']);
        }
        if ($roleCode === $user->role) {
            throw ValidationException::withMessages(['role' => 'Le rôle principal du compte ne se retire pas ici.']);
        }
        $role = Role::query()->where('code', $roleCode)->first();
        $actif = $role !== null && $user->roles()->whereKey($role->id)->wherePivot('status', 'active')->exists();
        if (! $actif) {
            throw ValidationException::withMessages(['role' => 'Ce rôle n’est pas attribué en plus du rôle principal.']);
        }
        $user->roles()->updateExistingPivot($role->id, [
            'status' => 'revoquee',
            'motif' => 'Révocation de l’habilitation',
        ]);
        $this->audit($actor, 'role.retirer', 'user', (string) $user->id, ['role_retire' => $roleCode], ['role' => $user->role, 'statut' => 'revoquee']);

        return $user->fresh();
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function definirPerimetre(User $actor, User $user, array $unitIds): User
    {
        $ids = collect($unitIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        DB::transaction(function () use ($actor, $user, $ids): void {
            DB::table('access_scopes')->where('user_id', $user->id)->where('scope_type', 'organization_unit')->delete();
            foreach ($ids as $id) {
                DB::table('access_scopes')->insert([
                    'user_id' => $user->id,
                    'scope_type' => 'organization_unit',
                    'scope_value' => (string) $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->audit($actor, 'utilisateur.perimetre', 'user', (string) $user->id, null, ['unites' => $ids->all()]);
        });

        return $user->fresh();
    }

    private function protegerDernierAdministrateur(User $user): void
    {
        if ($user->role !== 'administrateur_habilitations' || $user->account_status !== 'actif') {
            return;
        }
        $autres = User::query()
            ->where('role', 'administrateur_habilitations')
            ->where('account_status', 'actif')
            ->whereKeyNot($user->id)
            ->count();
        if ($autres === 0) {
            throw ValidationException::withMessages(['motif' => 'Le dernier administrateur des habilitations actif ne peut pas être désactivé.']);
        }
    }

    public function assertCompatible(string $primary, ?string $extra): void
    {
        if ($extra === null || $primary === $extra) {
            return;
        }
        $conflict = SodRule::query()
            ->where('blocking', true)
            ->where('active', true)
            ->where(function ($query) use ($primary, $extra) {
                $query->where(fn ($inner) => $inner->where('role_a', $primary)->where('role_b', $extra))
                    ->orWhere(fn ($inner) => $inner->where('role_a', $extra)->where('role_b', $primary));
            })
            ->first();
        if ($conflict) {
            throw ValidationException::withMessages(['role' => $conflict->label]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function delegate(User $actor, array $data): AdminDelegation
    {
        if ($data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'La fin de délégation doit suivre le début.']);
        }
        $status = $data['ends_on'] < now()->toDateString() ? 'expiree' : 'active';
        $delegation = AdminDelegation::query()->create([
            ...$data,
            'status' => $status,
            'approved_by' => $actor->id,
        ]);
        $this->audit($actor, 'delegation.creer', 'admin_delegation', (string) $delegation->id, null, [
            'delegant_id' => $delegation->delegant_id,
            'delegataire_id' => $delegation->delegataire_id,
            'statut' => $status,
        ]);

        return $delegation;
    }

    /**
     * @param  array{titulaire_id: int, interim_id: int, starts_on: string, ends_on: string, document?: string|null}  $data
     * @return array{id: int, statut: string, fonction: string, effectif: bool}
     */
    public function ouvrirInterim(User $actor, array $data): array
    {
        $titulaire = User::query()->findOrFail($data['titulaire_id']);
        $interim = User::query()->findOrFail($data['interim_id']);
        if ((int) $titulaire->id === (int) $interim->id) {
            throw ValidationException::withMessages(['interim_id' => 'Le titulaire et l’intérimaire doivent être deux comptes distincts.']);
        }
        if ($data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'La fin de l’intérim doit suivre le début.']);
        }
        $this->assertCompatible((string) $interim->role, (string) $titulaire->role);
        $deja = DB::table('substitutions')
            ->where('titulaire_id', $titulaire->id)
            ->where('interim_id', $interim->id)
            ->where('status', 'active')
            ->exists();
        if ($deja) {
            throw ValidationException::withMessages(['interim_id' => 'Un intérim est déjà ouvert pour ce couple.']);
        }

        $statut = $data['ends_on'] < now()->toDateString() ? 'cloture' : 'active';
        $id = DB::table('substitutions')->insertGetId([
            'titulaire_id' => $titulaire->id,
            'interim_id' => $interim->id,
            'fonction' => (string) $titulaire->role,
            'organization_unit_id' => $titulaire->organization_unit_id,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'document' => $data['document'] ?? null,
            'status' => $statut,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($actor, 'interim.ouvrir', 'substitution', (string) $id, null, [
            'titulaire_id' => $titulaire->id,
            'interim_id' => $interim->id,
            'fonction' => $titulaire->role,
            'statut' => $statut,
        ]);

        return [
            'id' => $id,
            'statut' => $statut,
            'fonction' => (string) $titulaire->role,
            'effectif' => $statut === 'active' && $data['starts_on'] <= now()->toDateString(),
        ];
    }

    /**
     * @return array{id: int, statut: string, effectif: bool}
     */
    public function cloturerInterim(User $actor, int $id): array
    {
        $row = DB::table('substitutions')->where('id', $id)->first();
        if ($row === null) {
            throw ValidationException::withMessages(['interim' => 'Cet intérim est introuvable.']);
        }
        if ($row->status !== 'active') {
            throw ValidationException::withMessages(['interim' => 'Cet intérim est déjà clos.']);
        }
        DB::table('substitutions')->where('id', $id)->update([
            'status' => 'cloture',
            'updated_at' => now(),
        ]);
        $this->audit($actor, 'interim.cloturer', 'substitution', (string) $id, ['statut' => 'active'], ['statut' => 'cloture']);

        return ['id' => $id, 'statut' => 'cloture', 'effectif' => false];
    }

    /**
     * @param  array{code: string, module: string, label: string, steps: list<array{code: string, label: string, actor_role?: string|null}>}  $data
     */
    public function createWorkflow(User $actor, array $data): WorkflowDefinition
    {
        return DB::transaction(function () use ($actor, $data) {
            $definition = WorkflowDefinition::query()->create([
                'code' => $data['code'],
                'module' => $data['module'],
                'label' => $data['label'],
            ]);
            $version = $definition->versions()->create([
                'version' => 1,
                'status' => 'brouillon',
            ]);
            $this->writeSteps($version, $data['steps']);
            $this->audit($actor, 'workflow.creer', 'workflow_definition', (string) $definition->id, null, ['code' => $definition->code]);

            return $definition->load('versions.steps');
        });
    }

    public function publish(User $actor, WorkflowDefinition $definition): WorkflowVersion
    {
        $version = $definition->versions()->where('status', 'brouillon')->latest('version')->first();
        if ($version === null || $version->steps()->count() === 0 || $version->steps()->where(fn ($query) => $query->whereNull('actor_role')->orWhere('actor_role', ''))->exists()) {
            throw ValidationException::withMessages(['workflow' => 'La publication exige au moins une étape avec un acteur.']);
        }

        return DB::transaction(function () use ($actor, $definition, $version) {
            $definition->versions()->where('status', 'actif')->update(['status' => 'remplace']);
            $version->forceFill([
                'status' => 'actif',
                'effective_on' => $version->effective_on ?? now()->toDateString(),
            ])->save();
            $this->audit($actor, 'workflow.publier', 'workflow_version', (string) $version->id, null, ['version' => $version->version]);

            return $version->fresh('steps');
        });
    }

    public function newVersion(User $actor, WorkflowDefinition $definition): WorkflowVersion
    {
        $current = $definition->versions()->where('status', 'actif')->latest('version')->first();
        if ($current === null) {
            throw ValidationException::withMessages(['workflow' => 'Seul un workflow publié peut être versionné.']);
        }

        return DB::transaction(function () use ($actor, $definition, $current) {
            $version = $definition->versions()->create([
                'version' => $current->version + 1,
                'status' => 'brouillon',
            ]);
            foreach ($current->steps as $step) {
                $version->steps()->create($step->only(['ordre', 'code', 'label', 'actor_role']));
            }
            $this->audit($actor, 'workflow.version', 'workflow_version', (string) $version->id, ['version' => $current->version], ['version' => $version->version]);

            return $version->fresh('steps');
        });
    }

    /**
     * @param  list<array{code: string, label: string, actor_role?: string|null}>  $steps
     */
    public function replaceSteps(User $actor, WorkflowVersion $version, array $steps): WorkflowVersion
    {
        if ($version->status !== 'brouillon') {
            throw ValidationException::withMessages(['workflow' => 'Un workflow publié ne se modifie pas en place. Créez une nouvelle version.']);
        }
        $this->writeSteps($version, $steps);
        $this->audit($actor, 'workflow.modifier', 'workflow_version', (string) $version->id, null, ['etapes' => count($steps)]);

        return $version->fresh('steps');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createThreshold(User $actor, array $data): AuthorizationThreshold
    {
        if ($data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'La période du seuil est incohérente.']);
        }
        $max = $data['max_amount'] ?? null;
        $overlap = AuthorizationThreshold::query()
            ->where('operation', $data['operation'])
            ->where('active', true)
            ->where('min_amount', '<=', $max ?? PHP_INT_MAX)
            ->where(function ($query) use ($data) {
                $query->whereNull('max_amount')->orWhere('max_amount', '>=', $data['min_amount']);
            })
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['min_amount' => 'Ce seuil chevauche une tranche déjà active.']);
        }
        $threshold = AuthorizationThreshold::query()->create($data);
        $this->audit($actor, 'seuil.creer', 'authorization_threshold', (string) $threshold->id, null, [
            'operation' => $threshold->operation,
            'min' => $threshold->min_amount,
            'max' => $threshold->max_amount,
        ]);

        return $threshold;
    }

    public function protectNomenclature(BudgetLine $line): void
    {
        if ($line->engagements()->exists() || $line->imputations()->exists()) {
            throw ValidationException::withMessages(['nomenclature' => 'Une nomenclature déjà utilisée ne peut pas être supprimée.']);
        }
        throw ValidationException::withMessages(['nomenclature' => 'La nomenclature se désactive par version, elle ne se supprime pas.']);
    }

    public function updateSetting(User $actor, string $key, string $value, ?string $motif): SystemSetting
    {
        $setting = SystemSetting::query()->firstOrCreate(['key' => $key], ['value' => null, 'critical' => true]);
        if ($setting->critical && blank($motif)) {
            throw ValidationException::withMessages(['motif' => 'Un paramètre critique exige un motif.']);
        }
        SettingVersion::query()->create([
            'system_setting_id' => $setting->id,
            'before' => $setting->value,
            'after' => $value,
            'actor_id' => $actor->id,
            'motif' => $motif,
        ]);
        $before = $setting->value;
        $setting->forceFill(['value' => $value])->save();
        $this->audit($actor, 'parametre.modifier', 'system_setting', (string) $setting->id, ['valeur' => $before], ['valeur' => $value], $motif);

        return $setting->fresh('versions');
    }

    public function updateBusinessRule(User $actor, BusinessRule $rule, string $value, bool $active, string $motif): BusinessRule
    {
        return DB::transaction(function () use ($actor, $rule, $value, $active, $motif) {
            $before = ['value' => $rule->value, 'active' => $rule->active];
            $rule->forceFill(['value' => $value, 'active' => $active])->save();
            $this->audit($actor, 'regle_metier.modifier', 'business_rule', (string) $rule->id, $before, [
                'value' => $rule->value,
                'active' => $rule->active,
            ], $motif);

            return $rule->fresh();
        });
    }

    public function updateSequence(User $actor, NumberSequence $sequence, int $lastValue): NumberSequence
    {
        if ($lastValue < $sequence->last_value) {
            throw ValidationException::withMessages(['last_value' => 'Une séquence déjà consommée ne peut pas être reculée.']);
        }
        $before = $sequence->last_value;
        $sequence->forceFill(['last_value' => $lastValue])->save();
        $this->audit($actor, 'numerotation.modifier', 'number_sequence', (string) $sequence->id, ['last_value' => $before], ['last_value' => $lastValue]);

        return $sequence;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeIntegration(User $actor, array $data): Integration
    {
        $secret = $data['secret'] ?? null;
        unset($data['secret']);
        $integration = Integration::query()->create([
            ...$data,
            'secret_encrypted' => filled($secret) ? Crypt::encryptString((string) $secret) : null,
        ]);
        $this->audit($actor, 'integration.creer', 'integration', (string) $integration->id, null, [
            'name' => $integration->name,
            'secret' => filled($secret) ? 'renseigné' : 'absent',
        ]);

        return $integration;
    }

    public function openSession(User $actor, User $subject, ?string $ip): UserSession
    {
        $session = UserSession::query()->create([
            'user_id' => $subject->id,
            'ip' => $ip,
            'last_seen' => now(),
        ]);
        $this->audit($actor, 'session.ouvrir', 'user_session', (string) $session->id, null, ['user_id' => $subject->id]);

        return $session;
    }

    public function revokeSession(User $actor, UserSession $session): UserSession
    {
        $session->forceFill(['revoked_at' => now()])->save();
        $this->audit($actor, 'session.revoquer', 'user_session', (string) $session->id, null, ['user_id' => $session->user_id]);

        return $session;
    }

    public function reopenExercice(User $actor, Exercice $exercice, string $motif): Exercice
    {
        if ($exercice->statut !== 'clos') {
            throw ValidationException::withMessages(['exercice' => 'Seul un exercice clos peut être rouvert.']);
        }
        $before = $exercice->statut;
        $exercice->forceFill(['statut' => 'ouvert'])->save();
        $this->audit($actor, 'exercice.rouvrir', 'exercice', (string) $exercice->id, ['statut' => $before], ['statut' => 'ouvert'], $motif);

        return $exercice;
    }

    /**
     * @param  list<array{code: string, label: string, actor_role?: string|null}>  $steps
     */
    private function writeSteps(WorkflowVersion $version, array $steps): void
    {
        $version->steps()->delete();
        foreach (array_values($steps) as $index => $step) {
            $version->steps()->create([
                'ordre' => $index + 1,
                'code' => $step['code'],
                'label' => $step['label'],
                'actor_role' => $step['actor_role'] ?? null,
            ]);
        }
    }

    /**
     * @param  array{label: string, description?: string|null, active: bool, motif: string}  $data
     */
    public function modifierRole(User $actor, Role $role, array $data): Role
    {
        if (! $data['active'] && $role->code === 'administrateur_habilitations') {
            throw ValidationException::withMessages([
                'active' => 'Le rôle d’administrateur des habilitations ne se désactive pas : il protège le dernier compte habilité.',
            ]);
        }
        $before = ['label' => $role->label, 'description' => $role->description, 'active' => $role->active];
        $role->fill([
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'active' => $data['active'],
        ])->save();
        $this->audit($actor, 'role.modifier', 'role', (string) $role->id, $before, [
            'label' => $role->label,
            'description' => $role->description,
            'active' => $role->active,
        ], $data['motif']);

        return $role;
    }

    public function supprimerRole(User $actor, Role $role, string $motif): void
    {
        if ($role->system) {
            throw ValidationException::withMessages(['role' => 'Un rôle système se désactive. Il ne se supprime pas.']);
        }
        $utilise = User::query()->where('role', $role->code)->exists()
            || DB::table('user_roles')->where('role_id', $role->id)->exists();
        if ($utilise) {
            throw ValidationException::withMessages(['role' => 'Ce rôle a déjà des habilitations. Révoquez-les ou désactivez le rôle.']);
        }
        $this->audit($actor, 'role.supprimer', 'role', (string) $role->id, ['code' => $role->code], null, $motif);
        $role->delete();
    }

    /**
     * @return array{code: string, permissions: list<string>}
     */
    public function reglerPermission(User $actor, Role $role, string $permissionCode, bool $accorder, string $motif): array
    {
        if (! HabilitationCatalogue::appliquee($permissionCode)) {
            throw ValidationException::withMessages([
                'permission' => 'Cette permission n’est pas une case de matrice. Elle suit le rôle, le périmètre ou le seuil, et n’est pas accordée ici.',
            ]);
        }
        app(HabilitationCatalogue::class)->assurer();
        $permission = Permission::query()->where('code', $permissionCode)->where('active', true)->first();
        if ($permission === null) {
            throw ValidationException::withMessages(['permission' => 'Cette permission n’existe pas ou est désactivée.']);
        }
        $avant = $role->permissions()->pluck('code')->all();
        if ($accorder) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        } else {
            $role->permissions()->detach($permission->id);
        }
        $role->unsetRelation('permissions');
        $apres = $role->permissions()->pluck('code')->all();
        $this->audit($actor, $accorder ? 'permission.accorder' : 'permission.retirer', 'role', (string) $role->id, [
            'permissions' => $avant,
        ], [
            'permission' => $permissionCode,
            'permissions' => $apres,
        ], $motif);

        return ['code' => $role->code, 'permissions' => $apres];
    }

    public function creerIncompatibilite(User $actor, string $roleA, string $roleB, string $label, string $motif): SodRule
    {
        if ($roleA === $roleB) {
            throw ValidationException::withMessages(['role_b' => 'Une incompatibilité oppose deux rôles distincts.']);
        }
        foreach ([$roleA, $roleB] as $code) {
            if (! Role::query()->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['role_a' => 'Les deux rôles doivent déjà exister.']);
            }
        }
        $existe = SodRule::query()
            ->where(function ($query) use ($roleA, $roleB) {
                $query->where(fn ($inner) => $inner->where('role_a', $roleA)->where('role_b', $roleB))
                    ->orWhere(fn ($inner) => $inner->where('role_a', $roleB)->where('role_b', $roleA));
            })
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['role_b' => 'Cette incompatibilité est déjà enregistrée.']);
        }
        $rule = SodRule::query()->create([
            'role_a' => $roleA,
            'role_b' => $roleB,
            'blocking' => true,
            'label' => $label,
            'active' => true,
            'justification' => $motif,
        ]);
        $this->audit($actor, 'sod.creer', 'sod_rule', (string) $rule->id, null, [
            'role_a' => $roleA,
            'role_b' => $roleB,
        ], $motif);

        return $rule;
    }

    public function desactiverIncompatibilite(User $actor, SodRule $rule, string $motif): SodRule
    {
        $rule->forceFill(['active' => false, 'justification' => $motif])->save();
        $this->audit($actor, 'sod.desactiver', 'sod_rule', (string) $rule->id, ['active' => true], ['active' => false], $motif);

        return $rule;
    }

    public function revoquerDelegation(User $actor, AdminDelegation $delegation, string $motif): AdminDelegation
    {
        if ($delegation->status !== 'active') {
            throw ValidationException::withMessages(['motif' => 'Seule une délégation active se révoque.']);
        }
        $delegation->forceFill(['status' => 'revoquee'])->save();
        $this->audit($actor, 'delegation.revoquer', 'admin_delegation', (string) $delegation->id, ['statut' => 'active'], ['statut' => 'revoquee'], $motif);

        return $delegation;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function audit(User $actor, string $action, string $objectType, ?string $objectId, ?array $before, ?array $after, ?string $motif = null): void
    {
        app(AuditService::class)->enregistrer($actor, $action, $objectType, $objectId, $before, $after, $motif);
    }
}
