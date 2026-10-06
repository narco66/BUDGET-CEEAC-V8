<?php

namespace App\Domains\Tasks\Http\Controllers;

use App\Domains\Administration\Models\AdminDelegation;
use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Services\TaskAudience;
use App\Domains\Tasks\Services\TaskDossierService;
use App\Domains\Tasks\Services\TaskProjector;
use App\Domains\Tasks\Services\TaskWording;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Notifications\NotificationTargetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /** Action des brouillons d’EB à compléter par leur auteur : suivis à part. */
    private const BROUILLON = 'completer';

    /** Fenêtre de calcul du délai moyen et des goulets. */
    private const FENETRE_JOURS = 90;

    /** @var array<string, array{titulaire: ?string, du: ?string, au: ?string}|null> */
    private array $delegations = [];

    /**
     * La lecture ne reprojette pas les tâches : la projection suit chaque
     * transition de dossier (ProjectTasks) et une passe quotidienne
     * (taches:projeter) recalcule priorités et échéances.
     */
    public function __construct(
        private readonly TaskDossierService $dossiers,
        private readonly TaskAudience $audience,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $base = $this->scope($user, $request);
        $query = (clone $base)->with(['assignee', 'preneur']);
        $this->filter($query, $request);
        $page = $query->paginate(min(100, max(5, (int) $request->integer('per_page', 25))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (WorkflowTask $task) => $this->payload($task, $user))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'tableau_de_bord' => $this->dashboard($user, $base),
            'notifications' => $user->unreadNotifications()->latest()->limit(5)->get()->map(
                fn ($notice) => app(NotificationTargetResolver::class)->carte($user, $notice)
            )->values(),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'nombre' => $this->visible($user)->where('status', '!=', 'terminee')->where('action', '!=', self::BROUILLON)->count(),
        ]);
    }

    public function show(Request $request, WorkflowTask $tache): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->canOpen($user, $tache), 403);
        $tache->load(['comments.author', 'assignee', 'preneur']);

        return response()->json(['data' => $this->payload($tache, $user, true)]);
    }

    public function start(Request $request, WorkflowTask $tache): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->canAct($user, $tache), 403);
        abort_unless($tache->isOpen(), 422, 'Cette tâche est déjà terminée.');
        if ($tache->status === 'en_cours' && $tache->started_by !== null && (int) $tache->started_by !== (int) $user->id) {
            abort(422, 'Cette tâche est déjà prise en charge par '.($tache->preneur?->name ?? 'un autre agent').'.');
        }
        $tache->forceFill([
            'status' => 'en_cours',
            'started_at' => $tache->started_at ?? now(),
            'started_by' => $user->id,
        ])->save();
        $this->audit($user, 'tache.prise_en_charge', $tache);

        return response()->json(['data' => $this->payload($tache->fresh(['preneur']), $user, true)]);
    }

    /**
     * Rend une tâche prise en charge à l’ensemble des titulaires du rôle :
     * par celui qui l’a prise, ou par l’encadrement de l’unité.
     */
    public function release(Request $request, WorkflowTask $tache): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->canRelease($user, $tache), 403);
        $tache->forceFill(['status' => 'a_traiter', 'started_at' => null, 'started_by' => null])->save();
        // La projection rétablit le statut exact (« retournée » si le dossier l’est).
        app(TaskProjector::class)->sync((string) $tache->entity_type, (int) $tache->entity_id);
        $this->audit($user, 'tache.liberation', $tache);

        return response()->json(['data' => $this->payload($tache->fresh(['preneur']), $user, true)]);
    }

    private function canRelease(User $user, WorkflowTask $task): bool
    {
        if (! $task->isOpen() || $task->status !== 'en_cours') {
            return false;
        }

        // Celui qui l’a prise, ou l’encadrement pour une tâche d’un autre rôle
        // que le sien : un pair ne retire pas une tâche à un collègue.
        return (int) $task->started_by === (int) $user->id
            || ($this->managesUnit($user)
                && $this->inUnit($user, $task)
                && ! in_array((string) $task->assigned_role, $this->actingRoles($user), true));
    }

    public function comment(Request $request, WorkflowTask $tache): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->canAct($user, $tache), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $tache->comments()->create([
            'author_id' => $user->id,
            'body' => $data['body'],
        ]);
        $this->audit($user, 'tache.commentaire', $tache, $data['body']);

        return response()->json(['data' => $this->payload($tache->fresh('comments.author'), $user, true)]);
    }

    /**
     * @return Builder<WorkflowTask>
     */
    private function scope(User $user, Request $request): Builder
    {
        if ($request->string('perimetre')->toString() === 'unite' && $this->managesUnit($user)) {
            return $this->unitScope($user);
        }

        return $this->visible($user);
    }

    private function managesUnit(User $user): bool
    {
        return array_intersect($this->actingRoles($user), ['directeur', 'commissaire', 'secretaire_general', 'directeur_budget', 'ordonnateur']) !== [];
    }

    /**
     * @return Builder<WorkflowTask>
     */
    private function unitScope(User $user): Builder
    {
        $query = WorkflowTask::query();
        $roles = $this->actingRoles($user);
        if (array_intersect($roles, ['directeur', 'commissaire']) !== []
            && array_intersect($roles, ['secretaire_general', 'directeur_budget', 'ordonnateur']) === []) {
            $query->whereIn('organization_unit_id', $this->unitsOf($user, $roles));
        }

        return $query;
    }

    /**
     * @return Builder<WorkflowTask>
     */
    private function visible(User $user): Builder
    {
        $roles = $this->actingRoles($user);
        $unitRoles = array_values(array_intersect($roles, ['directeur', 'commissaire']));
        $openRoles = array_values(array_diff($roles, ['directeur', 'commissaire']));

        return WorkflowTask::query()->where(function (Builder $query) use ($user, $unitRoles, $openRoles) {
            $query->where('assigned_user_id', $user->id);
            if ($openRoles !== []) {
                $query->orWhere(function (Builder $role) use ($openRoles) {
                    $role->whereNull('assigned_user_id')->whereIn('assigned_role', $openRoles);
                });
            }
            foreach ($unitRoles as $unitRole) {
                $query->orWhere(function (Builder $role) use ($user, $unitRole) {
                    $role->whereNull('assigned_user_id')
                        ->where('assigned_role', $unitRole)
                        ->whereIn('organization_unit_id', $this->audience->coveredUnits($user, $unitRole));
                });
            }
        });
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>
     */
    private function unitsOf(User $user, array $roles): array
    {
        $units = [];
        foreach (array_intersect($roles, TaskAudience::UNIT_ROLES) as $role) {
            $units = [...$units, ...$this->audience->coveredUnits($user, $role)];
        }

        return array_values(array_unique($units));
    }

    private function canSee(User $user, WorkflowTask $task): bool
    {
        return $this->visible($user)->whereKey($task->id)->exists();
    }

    private function canOpen(User $user, WorkflowTask $task): bool
    {
        return $this->canSee($user, $task) || ($this->managesUnit($user) && $this->inUnit($user, $task));
    }

    private function canAct(User $user, WorkflowTask $task): bool
    {
        return $task->isOpen() && ($this->matchesAssignee($user, $task) || $this->viaDelegation($user, $task));
    }

    private function inUnit(User $user, WorkflowTask $task): bool
    {
        if (array_intersect($this->actingRoles($user), ['directeur_budget', 'ordonnateur', 'secretaire_general']) !== []) {
            return true;
        }

        return $task->organization_unit_id !== null
            && in_array((int) $task->organization_unit_id, $this->unitsOf($user, $this->actingRoles($user)), true);
    }

    private function matchesAssignee(User $user, WorkflowTask $task): bool
    {
        return $this->audience->covers($user, $task, $this->actingRoles($user));
    }

    private function viaDelegation(User $user, WorkflowTask $task): bool
    {
        return $task->assigned_user_id === null && in_array($task->assigned_role, $this->delegatedRoles($user), true);
    }

    /**
     * @return list<string>
     */
    private function actingRoles(User $user): array
    {
        return $this->audience->actingRoles($user);
    }

    /**
     * @return list<string>
     */
    private function delegatedRoles(User $user): array
    {
        return $this->audience->delegatedRoles($user);
    }

    /**
     * @param  Builder<WorkflowTask>  $query
     */
    private function filter(Builder $query, Request $request): void
    {
        $view = $request->string('vue')->toString();
        $history = in_array($view, ['terminees', 'terminees_aujourdhui'], true) || $request->string('statut')->toString() === 'terminee';
        $query->when($history, fn (Builder $inner) => $inner->where('status', 'terminee'))
            ->when($view === 'terminees_aujourdhui', fn (Builder $inner) => $inner->whereDate('completed_at', today()))
            ->when(! $history, fn (Builder $inner) => $inner->where('status', '!=', 'terminee'))
            ->when($view === 'a_traiter', fn (Builder $inner) => $inner->where('action', '!=', self::BROUILLON))
            ->when($view === 'brouillons', fn (Builder $inner) => $inner->where('action', self::BROUILLON))
            ->when($view === 'retard', fn (Builder $inner) => $inner->whereDate('due_on', '<', today()))
            ->when($view === 'urgentes', fn (Builder $inner) => $inner->whereIn('priority', ['critique', 'haute']))
            ->when($view === 'retournees', fn (Builder $inner) => $inner->where('status', 'retournee'))
            ->when($view === 'en_cours', fn (Builder $inner) => $inner->where('status', 'en_cours'))
            ->when($request->string('module')->toString(), fn (Builder $inner, string $module) => $inner->where('module', $module))
            ->when($request->string('priorite')->toString(), fn (Builder $inner, string $priority) => $inner->where('priority', $priority))
            ->when($request->string('statut')->toString(), fn (Builder $inner, string $status) => $inner->where('status', $status))
            ->when($request->string('etape')->toString(), fn (Builder $inner, string $step) => $inner->where('step', $step))
            ->when($request->string('structure')->toString(), fn (Builder $inner, string $structure) => $this->contient($inner, 'structure', $structure))
            ->when($request->filled('exercice'), fn (Builder $inner) => $inner->where('exercice_year', (int) $request->input('exercice')))
            ->when($request->string('q')->toString(), function (Builder $inner, string $term) {
                $inner->where(function (Builder $search) use ($term) {
                    $this->contient($search, 'dossier_reference', $term);
                    $this->contient($search, 'objet', $term, 'or');
                    $this->contient($search, 'subject', $term, 'or');
                    $this->contient($search, 'demandeur', $term, 'or');
                });
            });

        match ($request->string('tri')->toString()) {
            'echeance' => $query->orderBy('due_on'),
            'priorite' => $query->orderByRaw("case priority when 'critique' then 1 when 'haute' then 2 when 'normale' then 3 else 4 end"),
            'montant' => $query->orderByDesc('amount'),
            'ancien' => $query->orderBy('assigned_at'),
            'module', 'type' => $query->orderBy('module')->orderByDesc('assigned_at'),
            'statut' => $query->orderBy('status')->orderByDesc('assigned_at'),
            'urgentes' => $query->orderByRaw('case when due_on is not null and due_on < ? then 0 when due_on is not null and due_on <= ? then 1 else 2 end', [today()->toDateString(), today()->addDays(2)->toDateString()])->orderBy('due_on'),
            default => $query->orderByDesc('assigned_at'),
        };
    }

    /**
     * Recherche insensible à la casse, identique sous PostgreSQL et SQLite.
     *
     * @param  Builder<WorkflowTask>  $query
     */
    private function contient(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $motif = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($term))).'%';
        $query->whereRaw('LOWER('.$column.') LIKE ?', [$motif], $boolean);
    }

    /**
     * @param  Builder<WorkflowTask>  $scope
     * @return array<string, mixed>
     */
    private function dashboard(User $user, Builder $scope): array
    {
        $open = fn () => (clone $scope)->where('status', '!=', 'terminee');
        $actives = fn () => $open()->where('action', '!=', self::BROUILLON);
        $threshold = (int) config('gesbudep.taches.montant_eleve', 5_000_000);
        // Délai moyen et goulets sur une fenêtre glissante : le calcul ne croît pas avec l’historique.
        $completed = (clone $scope)->where('status', 'terminee')
            ->whereNotNull('assigned_at')
            ->where('completed_at', '>=', now()->subDays(self::FENETRE_JOURS))
            ->get(['module', 'assigned_at', 'completed_at']);

        return [
            'a_traiter' => $actives()->count(),
            'brouillons' => $open()->where('action', self::BROUILLON)->count(),
            'urgentes' => $actives()->whereIn('priority', ['critique', 'haute'])->count(),
            'en_retard' => $actives()->whereNotNull('due_on')->whereDate('due_on', '<', today())->count(),
            'retournees' => $open()->where('status', 'retournee')->count(),
            'terminees_aujourdhui' => (clone $scope)->where('status', 'terminee')->whereDate('completed_at', today())->count(),
            'recues_aujourdhui' => (clone $scope)->whereDate('assigned_at', today())->count(),
            'terminees_semaine' => (clone $scope)->where('status', 'terminee')->where('completed_at', '>=', now()->startOfWeek())->count(),
            'delai_moyen' => $completed->isEmpty() ? null : (int) round($completed->avg(fn (WorkflowTask $task) => $task->assigned_at->diffInDays($task->completed_at, true))),
            'delai_fenetre_jours' => self::FENETRE_JOURS,
            'montant_eleve' => $actives()->where('amount', '>=', $threshold)->count(),
            'montant_eleve_total' => (int) $actives()->where('amount', '>=', $threshold)->sum('amount'),
            'vue_unite' => $this->managesUnit($user),
            // Étapes présentes dans le périmètre, pour un filtre par liste.
            'etapes' => $open()->whereNotNull('step')->distinct()->orderBy('step')->pluck('step')
                ->map(fn (string $step) => ['valeur' => $step, 'libelle' => TaskWording::etape($step)])
                ->sortBy('libelle')->values(),
            'goulets' => $completed
                ->groupBy('module')
                ->map(fn ($rows, string $module) => [
                    'module' => $module,
                    'heures' => (int) round($rows->avg(fn (WorkflowTask $task) => $task->assigned_at->diffInHours($task->completed_at, true))),
                ])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WorkflowTask $task, User $user, bool $detail = false): array
    {
        // « Personnelle » : au titre des rôles détenus en propre (principal,
        // habilitation, intérim). Sinon, la tâche n’est accessible que par
        // délégation et l’écran le dit, avec le titulaire.
        $personal = $this->audience->covers($user, $task, $user->heldRoleCodes());
        $deleguee = ! $personal && $this->viaDelegation($user, $task);
        $payload = [
            'id' => $task->id,
            'reference' => $task->reference,
            'module' => $task->module,
            'type' => $this->typeOf($task->module),
            'dossier' => $task->dossier_reference,
            'objet' => $task->objet,
            'sujet' => $task->subject,
            'action' => $task->action,
            'action_libelle' => TaskWording::action($task->action),
            'etape' => $task->step,
            'etape_libelle' => TaskWording::etape($task->step),
            'role' => $task->assigned_role,
            'role_libelle' => $task->assignee?->name ?? TaskWording::role($task->assigned_role),
            'priorite' => $task->priority,
            'priorite_libelle' => TaskWording::priorite($task->priority),
            'statut' => $task->status,
            'statut_libelle' => $this->statusLabel($task),
            'montant' => $task->amount,
            'demandeur' => $task->demandeur,
            'structure' => $task->structure,
            'exercice' => $task->exercice_year,
            'lien' => $task->lien,
            'recue_le' => $task->assigned_at?->toDateTimeString(),
            'echeance' => $task->due_on?->toDateString(),
            'delai_jours' => $task->due_on ? (int) today()->diffInDays($task->due_on) : null,
            'en_retard' => $task->isLate(),
            'prise_le' => $task->started_at?->toDateTimeString(),
            'prise_par' => $task->status === 'en_cours' ? $task->preneur?->name : null,
            'prise_par_moi' => $task->status === 'en_cours' && (int) $task->started_by === (int) $user->id,
            'peut_liberer' => $this->canRelease($user, $task),
            'terminee_le' => $task->completed_at?->toDateTimeString(),
            'duree_heures' => ($task->assigned_at && $task->completed_at) ? (int) $task->assigned_at->diffInHours($task->completed_at, true) : null,
            'completion_action' => $task->completion_action,
            'peut_agir' => $task->isOpen() && ($personal || $deleguee),
            'deleguee' => $deleguee,
            'delegation' => $deleguee ? $this->delegationOf($user, (string) $task->assigned_role) : null,
            'commentaires' => $detail ? $task->comments->map(fn ($comment) => [
                'auteur' => $comment->author?->name,
                'fonction' => $comment->author?->function_title,
                'texte' => $comment->body,
                'le' => $comment->created_at?->toDateTimeString(),
            ]) : [],
        ];

        if ($detail) {
            $payload = [...$payload, ...$this->dossiers->present($task)];
        }

        return $payload;
    }

    private function typeOf(string $module): string
    {
        return match ($module) {
            'eb' => 'EB',
            'engagement' => 'ENG',
            'liquidation' => 'LIQ',
            'ordonnancement' => 'ORD',
            'paiement' => 'PAI',
            'se' => 'S&E',
            'recette' => 'REC',
            'preparation' => 'PRÉP',
            default => strtoupper($module),
        };
    }

    private function statusLabel(WorkflowTask $task): string
    {
        if ($task->status === 'terminee') {
            return match ($task->completion_action) {
                'rejetee' => 'Rejetée',
                'annulee' => 'Annulée',
                default => 'Terminée',
            };
        }
        if ($task->status === 'en_cours') {
            return 'En cours';
        }
        if ($task->status === 'retournee') {
            return 'Retournée pour correction';
        }

        return match ($task->action) {
            'valider' => 'À valider',
            'viser' => 'À viser',
            'signer' => 'À signer',
            'approuver' => 'À approuver',
            'certifier' => 'À certifier',
            'corriger' => 'À corriger',
            'completer' => 'À compléter',
            default => 'À traiter',
        };
    }

    /**
     * Délégation qui ouvre la tâche : celle dont le délégant porte le rôle attendu.
     *
     * @return array{titulaire: ?string, du: ?string, au: ?string}|null
     */
    private function delegationOf(User $user, string $role): ?array
    {
        if (array_key_exists($role, $this->delegations)) {
            return $this->delegations[$role];
        }
        $row = AdminDelegation::query()
            ->where('delegataire_id', $user->id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', today())
            ->whereDate('ends_on', '>=', today())
            ->whereHas('delegant', fn (Builder $query) => $query->where('role', $role))
            ->with('delegant')
            ->first();

        return $this->delegations[$role] = $row === null ? null : [
            'titulaire' => $row->delegant?->name,
            'du' => $row->starts_on?->toDateString(),
            'au' => $row->ends_on?->toDateString(),
        ];
    }

    private function audit(User $user, string $action, WorkflowTask $task, ?string $motif = null): void
    {
        AuditEvent::query()->create([
            'actor_id' => $user->id,
            'role' => $user->role,
            'action' => $action,
            'object_type' => 'workflow_task',
            'object_id' => (string) $task->id,
            'after' => ['dossier' => $task->dossier_reference, 'action_attendue' => $task->action],
            'motif' => $motif,
            'result' => 'succes',
            'ip' => request()->ip(),
        ]);
    }
}
