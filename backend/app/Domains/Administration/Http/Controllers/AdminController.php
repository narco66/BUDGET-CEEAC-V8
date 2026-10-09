<?php

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Models\AdminDelegation;
use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Administration\Models\AuthorizationThreshold;
use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Administration\Models\Integration;
use App\Domains\Administration\Models\NumberSequence;
use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SodRule;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Models\UserSession;
use App\Domains\Administration\Models\WorkflowDefinition;
use App\Domains\Administration\Models\WorkflowVersion;
use App\Domains\Administration\Services\AccessExplanation;
use App\Domains\Administration\Services\AdministrationService;
use App\Domains\Administration\Services\HabilitationCatalogue;
use App\Domains\Administration\Services\SecurityPolicy;
use App\Domains\Administration\Support\AdminGate;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdministrationService $administration,
        private readonly AccessExplanation $explanation,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');
        $users = User::query()->whereNotNull('role');
        $delegations = AdminDelegation::query()->get();

        return response()->json([
            'utilisateurs_actifs' => (clone $users)->where('account_status', 'actif')->count(),
            'utilisateurs_suspendus' => (clone $users)->where('account_status', 'suspendu')->count(),
            'utilisateurs_desactives' => (clone $users)->where('account_status', 'desactive')->count(),
            'roles' => Role::query()->where('active', true)->count(),
            'permissions' => Permission::query()->count(),
            'conflits_sod' => SodRule::query()->where('blocking', true)->where('active', true)->count(),
            'delegations_actives' => $delegations->filter(fn (AdminDelegation $row) => $row->effective())->count(),
            'delegations_expirantes' => $delegations->filter(fn (AdminDelegation $row) => $row->effective() && $row->ends_on?->lte(now()->addDays(15)))->count(),
            'workflows_actifs' => WorkflowDefinition::query()->whereHas('versions', fn ($query) => $query->where('status', 'actif'))->count(),
            'exercices_ouverts' => Exercice::query()->whereIn('statut', ['ouvert', 'executoire'])->count(),
            'alertes' => $this->alerts(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => User::query()->with('organizationUnit')->whereNotNull('role')->orderBy('name')->get()->map(fn (User $user) => $this->userPayload($user)),
        ]);
    }

    public function structures(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => OrganizationUnit::query()->with('parent')->orderBy('name')->get()->map(fn (OrganizationUnit $unit) => [
                'id' => $unit->id,
                'parent_id' => $unit->parent_id,
                'kind' => $unit->kind,
                'label' => $unit->structureLabel(),
            ]),
        ]);
    }

    public function showUser(Request $request, User $user): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => $this->userPayload($user->load('organizationUnit', 'roles')),
            'audit' => AuditEvent::query()->where('object_type', 'user')->where('object_id', (string) $user->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function storeUser(Request $request, SecurityPolicy $policy): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'prenom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'unique:users,email'],
            'matricule' => ['nullable', 'string', 'max:32', 'unique:users,matricule'],
            'telephone' => ['nullable', 'string', 'max:32'],
            'organization_unit_id' => ['required', 'integer', 'exists:organization_units,id'],
            'fonction' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'exists:roles,code,active,1'],
            'initiales' => ['required', 'string', 'max:8'],
            'password' => ['required', 'string', 'min:'.$policy->minPasswordLength()],
            'mfa_required' => ['sometimes', 'boolean'],
        ]);
        $user = $this->administration->createUser($request->user(), $data);

        return response()->json(['data' => $this->userPayload($user)], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'unique:users,email,'.$user->id],
            'matricule' => ['nullable', 'string', 'max:32', 'unique:users,matricule,'.$user->id],
            'telephone' => ['nullable', 'string', 'max:32'],
            'organization_unit_id' => ['required', 'integer', 'exists:organization_units,id'],
            'fonction' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'exists:roles,code,active,1'],
            'initiales' => ['required', 'string', 'max:8'],
            'mfa_required' => ['sometimes', 'boolean'],
        ]);
        $user = $this->administration->updateUser($request->user(), $user, $data);

        return response()->json(['data' => $this->userPayload($user->load('organizationUnit', 'roles'))]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $user = $this->administration->deactivate($request->user(), $user, $data['motif']);

        return response()->json(['data' => $this->userPayload($user)]);
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['role' => ['required', 'string', 'max:64']]);
        $user = $this->administration->assignRole($request->user(), $user, $data['role']);

        return response()->json(['data' => $this->userPayload($user->load('roles'))]);
    }

    public function removeRole(Request $request, User $user): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['role' => ['required', 'string', 'max:64']]);
        $user = $this->administration->retirerRole($request->user(), $user, $data['role']);

        return response()->json(['data' => $this->userPayload($user->load('organizationUnit', 'roles'))]);
    }

    public function catalogue(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');

        return response()->json(['data' => $this->explanation->catalogue()]);
    }

    public function expliquer(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'action' => ['required', 'string', 'max:64'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'montant' => ['nullable', 'integer', 'min:0'],
        ]);
        $cible = User::query()->findOrFail($data['user_id']);

        return response()->json([
            'data' => $this->explanation->expliquer(
                $cible,
                $data['action'],
                isset($data['organization_unit_id']) ? (int) $data['organization_unit_id'] : null,
                isset($data['montant']) ? (int) $data['montant'] : null,
            ),
        ]);
    }

    public function updateScope(Request $request, User $user): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'unites' => ['present', 'array'],
            'unites.*' => ['integer', 'distinct', 'exists:organization_units,id'],
        ]);
        $user = $this->administration->definirPerimetre($request->user(), $user, $data['unites']);

        return response()->json(['data' => $this->userPayload($user->load('organizationUnit', 'roles'))]);
    }

    public function roles(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => Role::query()->with('permissions')->orderBy('label')->get(),
            'permissions' => Permission::query()->orderBy('code')->get(),
        ]);
    }

    public function matrix(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');
        app(HabilitationCatalogue::class)->assurer();
        $recherche = trim($request->string('q')->toString());
        $roles = Role::query()->with('permissions')->orderBy('label')
            ->when($recherche !== '', function ($query) use ($recherche) {
                $query->where(function ($inner) use ($recherche) {
                    $inner->where('label', 'like', '%'.$recherche.'%')
                        ->orWhere('code', 'like', '%'.$recherche.'%');
                });
            })
            ->get();

        return response()->json([
            'roles' => $roles->map(fn (Role $role) => [
                'code' => $role->code,
                'label' => $role->label,
                'description' => $role->description,
                'sensible' => $role->sensitive,
                'actif' => $role->active,
                'systeme' => $role->system,
                'permissions' => $role->permissions->pluck('code'),
            ]),
            'permissions_appliquees' => collect(app(HabilitationCatalogue::class)->catalogue())
                ->where('appliquee', true)
                ->values(),
            'conflits' => SodRule::query()->orderBy('label')->get(),
        ]);
    }

    public function updateRole(Request $request, string $code): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $role = Role::query()->where('code', $code)->firstOrFail();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['required', 'boolean'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->administration->modifierRole($request->user(), $role, $data)]);
    }

    public function destroyRole(Request $request, string $code): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $role = Role::query()->where('code', $code)->firstOrFail();
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->administration->supprimerRole($request->user(), $role, $data['motif']);

        return response()->json(['data' => ['code' => $code, 'supprime' => true]]);
    }

    public function grantPermission(Request $request, string $code): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $role = Role::query()->where('code', $code)->firstOrFail();
        $data = $request->validate([
            'permission' => ['required', 'string', 'max:120'],
            'accorder' => ['required', 'boolean'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $this->administration->reglerPermission($request->user(), $role, $data['permission'], $data['accorder'], $data['motif']),
        ]);
    }

    public function storeIncompatibility(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'role_a' => ['required', 'string', 'max:64'],
            'role_b' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:255'],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $rule = $this->administration->creerIncompatibilite($request->user(), $data['role_a'], $data['role_b'], $data['label'], $data['motif']);

        return response()->json(['data' => ['id' => $rule->id, 'label' => $rule->label]], 201);
    }

    public function deactivateIncompatibility(Request $request, SodRule $sodRule): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $rule = $this->administration->desactiverIncompatibilite($request->user(), $sodRule, $data['motif']);

        return response()->json(['data' => ['id' => $rule->id, 'active' => $rule->active]]);
    }

    public function revokeDelegation(Request $request, AdminDelegation $delegation): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $delegation = $this->administration->revoquerDelegation($request->user(), $delegation, $data['motif']);

        return response()->json(['data' => ['id' => $delegation->id, 'statut' => $delegation->status, 'effective' => $delegation->effective()]]);
    }

    public function delegations(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => AdminDelegation::query()->with(['delegant', 'delegataire'])->latest('id')->get()->map(fn (AdminDelegation $row) => [
                'id' => $row->id,
                'delegant' => $row->delegant?->name,
                'delegataire' => $row->delegataire?->name,
                'fonction' => $row->fonction,
                'perimetre' => $row->perimetre,
                'debut' => $row->starts_on?->toDateString(),
                'fin' => $row->ends_on?->toDateString(),
                'motif' => $row->motif,
                'statut' => $row->status,
                'effective' => $row->effective(),
            ]),
        ]);
    }

    public function storeDelegation(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'delegant_id' => ['required', 'integer', 'exists:users,id'],
            'delegataire_id' => ['required', 'integer', 'different:delegant_id', 'exists:users,id'],
            'fonction' => ['required', 'string', 'max:120'],
            'perimetre' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'motif' => ['required', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:64'],
        ]);
        $delegation = $this->administration->delegate($request->user(), $data);

        return response()->json([
            'id' => $delegation->id,
            'statut' => $delegation->status,
            'effective' => $delegation->effective(),
        ], 201);
    }

    public function interims(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');
        $today = now()->toDateString();

        return response()->json([
            'data' => DB::table('substitutions')
                ->join('users as titulaires', 'titulaires.id', '=', 'substitutions.titulaire_id')
                ->join('users as interimaires', 'interimaires.id', '=', 'substitutions.interim_id')
                ->orderByDesc('substitutions.id')
                ->get([
                    'substitutions.id',
                    'substitutions.fonction',
                    'substitutions.starts_on',
                    'substitutions.ends_on',
                    'substitutions.status',
                    'titulaires.name as titulaire',
                    'interimaires.name as interim',
                ])
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'titulaire' => $row->titulaire,
                    'interim' => $row->interim,
                    'fonction' => $row->fonction,
                    'debut' => $row->starts_on,
                    'fin' => $row->ends_on,
                    'statut' => $row->status,
                    'effectif' => $row->status === 'active' && $row->starts_on <= $today && $row->ends_on >= $today,
                ]),
        ]);
    }

    public function storeInterim(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'titulaire_id' => ['required', 'integer', 'exists:users,id'],
            'interim_id' => ['required', 'integer', 'different:titulaire_id', 'exists:users,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'document' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->administration->ouvrirInterim($request->user(), $data)], 201);
    }

    public function closeInterim(Request $request, int $substitution): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');

        return response()->json(['data' => $this->administration->cloturerInterim($request->user(), $substitution)]);
    }

    public function workflows(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => WorkflowDefinition::query()->with('versions.steps')->orderBy('code')->get(),
        ]);
    }

    public function storeWorkflow(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:workflow_definitions,code'],
            'module' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:160'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.code' => ['required', 'string', 'max:64'],
            'steps.*.label' => ['required', 'string', 'max:160'],
            'steps.*.actor_role' => ['nullable', 'string', 'max:64'],
        ]);
        $definition = $this->administration->createWorkflow($request->user(), $data);

        return response()->json(['data' => $definition], 201);
    }

    public function publishWorkflow(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $version = $this->administration->publish($request->user(), $definition);

        return response()->json(['version' => $version->version, 'statut' => $version->status]);
    }

    public function versionWorkflow(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $version = $this->administration->newVersion($request->user(), $definition);

        return response()->json(['version' => $version->version, 'statut' => $version->status], 201);
    }

    public function updateWorkflowVersion(Request $request, WorkflowVersion $version): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.code' => ['required', 'string', 'max:64'],
            'steps.*.label' => ['required', 'string', 'max:160'],
            'steps.*.actor_role' => ['nullable', 'string', 'max:64'],
        ]);
        $version = $this->administration->replaceSteps($request->user(), $version, $data['steps']);

        return response()->json(['statut' => $version->status, 'etapes' => $version->steps->count()]);
    }

    public function thresholds(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json(['data' => AuthorizationThreshold::query()->orderBy('operation')->orderBy('min_amount')->get()]);
    }

    public function storeThreshold(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:authorization_thresholds,code'],
            'operation' => ['required', 'string', 'max:64'],
            'min_amount' => ['required', 'integer', 'min:0'],
            'max_amount' => ['nullable', 'integer', 'gte:min_amount'],
            'actor_role' => ['required', 'string', 'max:64'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'document' => ['nullable', 'string', 'max:64'],
        ]);
        $threshold = $this->administration->createThreshold($request->user(), $data + ['active' => true]);

        return response()->json(['data' => $threshold], 201);
    }

    public function destroyNomenclature(Request $request, BudgetLine $budgetLine): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $this->administration->protectNomenclature($budgetLine);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => SystemSetting::query()->orderBy('key')->get(),
            'regles_metier' => BusinessRule::query()->orderBy('label')->get(),
            'sequences' => NumberSequence::query()->orderBy('code')->get(),
            'referentiels' => DB::table('reference_values')->orderBy('set_code')->orderBy('sort_order')->get(),
            'pieces' => DB::table('document_types')->orderBy('code')->get(),
            'modeles' => DB::table('document_templates')->orderBy('code')->get(),
            'notifications' => DB::table('notification_templates')->orderBy('code')->get(['id', 'code', 'subject', 'channel', 'locale', 'status']),
            'sla' => DB::table('sla_rules')->orderBy('module')->get(),
            'securite' => DB::table('security_policies')->first(),
            'fonctions' => DB::table('institutional_functions')->orderBy('label')->get(),
            'calendrier' => DB::table('holidays')->orderBy('holiday_on')->get(),
            'exercices' => Exercice::query()->orderByDesc('annee')->get(),
        ]);
    }

    public function updateBusinessRule(Request $request, BusinessRule $businessRule): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'value' => ['required', 'numeric', 'min:0'],
            'active' => ['required', 'boolean'],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $rule = $this->administration->updateBusinessRule(
            $request->user(),
            $businessRule,
            (string) $data['value'],
            (bool) $data['active'],
            $data['motif'],
        );

        return response()->json(['data' => $rule]);
    }

    public function updateSetting(Request $request, string $key): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'value' => ['required', 'string', 'max:2000'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $setting = $this->administration->updateSetting($request->user(), $key, $data['value'], $data['motif'] ?? null);

        return response()->json([
            'cle' => $setting->key,
            'valeur' => $setting->value,
            'historique' => $setting->versions()->latest('id')->get(['before', 'after', 'motif', 'created_at']),
        ]);
    }

    public function updateSequence(Request $request, NumberSequence $sequence): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate(['last_value' => ['required', 'integer', 'min:0']]);
        $sequence = $this->administration->updateSequence($request->user(), $sequence, $data['last_value']);

        return response()->json(['code' => $sequence->code, 'last_value' => $sequence->last_value]);
    }

    public function integrations(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => Integration::query()->latest('id')->get()->map(fn (Integration $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'system' => $row->system,
                'environment' => $row->environment,
                'endpoint' => $row->endpoint,
                'auth_mode' => $row->auth_mode,
                'statut' => $row->status,
                'secret_renseigne' => filled($row->secret_encrypted),
                'derniere_erreur' => $row->last_error,
            ]),
        ]);
    }

    public function storeIntegration(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'system' => ['required', 'string', 'max:64'],
            'environment' => ['required', 'string', 'max:32'],
            'endpoint' => ['required', 'string', 'max:255'],
            'auth_mode' => ['required', 'string', 'max:32'],
            'secret' => ['nullable', 'string', 'max:255'],
        ]);
        $integration = $this->administration->storeIntegration($request->user(), $data + ['status' => 'inactive']);

        return response()->json([
            'id' => $integration->id,
            'name' => $integration->name,
            'secret_renseigne' => filled($integration->secret_encrypted),
        ], 201);
    }

    public function sessions(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json([
            'data' => UserSession::query()->with('user')->latest('id')->limit(50)->get()->map(fn (UserSession $row) => [
                'id' => $row->id,
                'utilisateur' => $row->user?->name,
                'ip' => $row->ip,
                'vue_le' => $row->last_seen?->toDateTimeString(),
                'revoquee_le' => $row->revoked_at?->toDateTimeString(),
                // Sans activité depuis la durée de session, la session n’ouvre plus rien : elle est expirée.
                'expiree' => $row->revoked_at === null && ($row->last_seen === null || $row->last_seen->lt(now()->subMinutes((int) config('session.lifetime')))),
            ]),
        ]);
    }

    public function openSession(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $session = $this->administration->openSession($request->user(), User::query()->findOrFail($data['user_id']), $request->ip());

        return response()->json(['id' => $session->id], 201);
    }

    public function revokeSession(Request $request, UserSession $userSession): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $userSession = $this->administration->revokeSession($request->user(), $userSession);

        return response()->json(['id' => $userSession->id, 'revoquee_le' => $userSession->revoked_at?->toDateTimeString()]);
    }

    public function reopenExercice(Request $request, Exercice $exercice): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $exercice = $this->administration->reopenExercice($request->user(), $exercice, $data['motif']);

        return response()->json(['annee' => $exercice->annee, 'statut' => $exercice->statut]);
    }

    public function audit(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');
        $rows = AuditEvent::query()->with('actor')->latest('id')
            ->when($request->string('action')->toString(), fn ($query, $action) => $query->where('action', $action))
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'le' => $event->created_at?->toDateTimeString(),
                'acteur' => $event->actor?->name,
                'role' => $event->role,
                'action' => $event->action,
                'objet' => $event->object_type,
                'objet_id' => $event->object_id,
                'avant' => $event->before,
                'apres' => $event->after,
                'motif' => $event->motif,
                'resultat' => $event->result,
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * @return list<array{niveau: string, message: string}>
     */
    private function alerts(): array
    {
        $alerts = [];
        $expiring = AdminDelegation::query()->where('status', 'active')->whereDate('ends_on', '<=', now()->addDays(15))->whereDate('ends_on', '>=', now())->count();
        if ($expiring > 0) {
            $alerts[] = ['niveau' => 'attention', 'message' => $expiring.' délégation(s) arrivent à échéance sous 15 jours.'];
        }
        $late = Exercice::query()->whereIn('statut', ['ouvert', 'executoire'])->whereDate('date_fin', '<', now())->count();
        if ($late > 0) {
            $alerts[] = ['niveau' => 'critique', 'message' => $late.' exercice(s) encore ouverts après leur date de fin.'];
        }
        if (SodRule::query()->where('blocking', true)->doesntExist()) {
            $alerts[] = ['niveau' => 'critique', 'message' => 'Aucune règle de séparation des fonctions n’est active.'];
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'nom' => $user->name,
            'email' => $user->email,
            'matricule' => $user->matricule,
            'telephone' => $user->phone,
            'initiales' => $user->initials,
            'fonction' => $user->function_title,
            'role' => $user->role,
            'organization_unit_id' => $user->organization_unit_id,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('code') : [],
            'structure' => $user->organizationUnit?->structureLabel(),
            'statut' => $user->account_status,
            'mfa' => $user->mfa_required,
            'perimetre' => DB::table('access_scopes')
                ->where('user_id', $user->id)
                ->where('scope_type', 'organization_unit')
                ->orderBy('id')
                ->pluck('scope_value')
                ->map(fn (mixed $value): int => (int) $value)
                ->values()
                ->all(),
            'source' => $user->identity_source,
            'derniere_connexion' => $user->last_login_at?->toDateTimeString(),
            'desactive_le' => $user->deactivated_at?->toDateTimeString(),
        ];
    }
}
