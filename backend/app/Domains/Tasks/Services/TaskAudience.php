<?php

namespace App\Domains\Tasks\Services;

use App\Domains\Administration\Models\AdminDelegation;
use App\Domains\Organization\Models\OrganizationAssignment;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Services\OrganizationService;
use App\Domains\Organization\Services\WorkflowActorResolver;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use Illuminate\Support\Collection;

/**
 * Qui reçoit et qui voit une tâche. Une seule règle pour la notification,
 * la liste « Mes tâches » et l’ouverture depuis une notification.
 *
 * Directeur et commissaire suivent la même règle que les circuits : le
 * dossier et ses structures parentes jusqu’à la direction (ou au département
 * technique) inclus, plus les occupants de la fonction dans ces structures.
 */
class TaskAudience
{
    public const UNIT_ROLES = ['directeur', 'commissaire'];

    /** @var array<string, list<int>> */
    private array $chains = [];

    /** @var array<string, list<int>> */
    private array $covered = [];

    /** @var array<int, list<string>> */
    private array $delegated = [];

    public function __construct(
        private readonly WorkflowActorResolver $resolver,
        private readonly OrganizationService $organization,
        private readonly RoleHolders $holders,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function recipients(WorkflowTask $task): Collection
    {
        if ($task->assigned_user_id !== null) {
            $user = User::query()->whereKey($task->assigned_user_id)->where('account_status', 'actif')->first();

            return $user ? collect([$user]) : collect();
        }

        return $this->forRole((string) $task->assigned_role, $task->organization_unit_id);
    }

    /**
     * Titulaires d’un rôle, limités au périmètre structurel du dossier pour
     * les rôles rattachés à une structure.
     *
     * @return Collection<int, User>
     */
    public function forRole(string $role, ?int $unitId = null): Collection
    {
        $query = $this->holders->query($role);
        if (! in_array($role, self::UNIT_ROLES, true) || $unitId === null) {
            return $query->get();
        }

        $chain = $this->chain($role, $unitId);
        $users = $query->whereIn('organization_unit_id', $chain)->get();
        $position = $this->organization->codeFonctionDuRole($role);
        if ($position !== null) {
            $occupants = collect($chain)->flatMap(fn (int $id) => $this->organization->occupants($id, $position))->unique()->all();
            if ($occupants !== []) {
                $users = $users->merge($this->holders->query($role)->whereIn('id', $occupants)->get());
            }
        }

        return $users->unique('id')->values();
    }

    /**
     * Rôles exercés aujourd’hui : rôles détenus (principal, habilitations,
     * intérims) et rôles reçus par délégation administrative.
     *
     * @return list<string>
     */
    public function actingRoles(User $user): array
    {
        return array_values(array_unique([...$user->heldRoleCodes(), ...$this->delegatedRoles($user)]));
    }

    /**
     * @return list<string>
     */
    public function delegatedRoles(User $user): array
    {
        return $this->delegated[$user->id] ??= AdminDelegation::query()
            ->where('delegataire_id', $user->id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', today())
            ->whereDate('ends_on', '>=', today())
            ->with('delegant')
            ->get()
            ->map(fn (AdminDelegation $row) => $row->delegant?->role)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Tâches ouvertes que l’utilisateur peut traiter.
     *
     * @return Collection<int, WorkflowTask>
     */
    public function openTasksFor(User $user): Collection
    {
        $roles = $this->actingRoles($user);

        return WorkflowTask::query()
            ->where('status', '!=', 'terminee')
            ->where(fn ($query) => $query->where('assigned_user_id', $user->id)->orWhere(fn ($role) => $role->whereNull('assigned_user_id')->whereIn('assigned_role', $roles)))
            ->orderBy('due_on')
            ->get()
            ->filter(fn (WorkflowTask $task) => $this->covers($user, $task, $roles))
            ->values();
    }

    /**
     * @param  list<string>  $roles  rôles exercés par l’utilisateur
     */
    public function covers(User $user, WorkflowTask $task, array $roles): bool
    {
        if ($task->assigned_user_id !== null) {
            return (int) $task->assigned_user_id === (int) $user->id;
        }
        $role = (string) $task->assigned_role;
        if (! in_array($role, $roles, true)) {
            return false;
        }
        if (! in_array($role, self::UNIT_ROLES, true)) {
            return true;
        }

        return $task->organization_unit_id !== null
            && in_array((int) $task->organization_unit_id, $this->coveredUnits($user, $role), true);
    }

    /**
     * Structures de dossier dont l’utilisateur est le directeur (ou le
     * commissaire) compétent.
     *
     * @return list<int>
     */
    public function coveredUnits(User $user, string $role): array
    {
        $key = $user->id.'|'.$role;
        if (isset($this->covered[$key])) {
            return $this->covered[$key];
        }

        $bases = $user->organization_unit_id !== null ? [(int) $user->organization_unit_id] : [];
        $position = $this->organization->codeFonctionDuRole($role);
        if ($position !== null) {
            $bases = [...$bases, ...$this->occupiedUnits($user, $position)];
        }
        if ($bases === []) {
            return $this->covered[$key] = [];
        }

        $units = [];
        foreach (OrganizationUnit::query()->pluck('id') as $id) {
            if (array_intersect($this->chain($role, (int) $id), $bases) !== []) {
                $units[] = (int) $id;
            }
        }

        return $this->covered[$key] = $units;
    }

    /**
     * @return list<int>
     */
    private function chain(string $role, int $unitId): array
    {
        $key = $role.'|'.$unitId;
        if (isset($this->chains[$key])) {
            return $this->chains[$key];
        }
        $unit = OrganizationUnit::query()->find($unitId);
        if (! $unit instanceof OrganizationUnit) {
            return $this->chains[$key] = [$unitId];
        }

        return $this->chains[$key] = $role === 'commissaire'
            ? $this->resolver->unitesCommissaire($unit)
            : $this->resolver->unitesDirection($unit);
    }

    /**
     * @return list<int>
     */
    private function occupiedUnits(User $user, string $position): array
    {
        return OrganizationAssignment::query()
            ->where('user_id', $user->id)
            ->where('statut', 'active')
            ->whereHas('position', fn ($query) => $query->where('code', $position)->where('is_active', true))
            ->pluck('organization_unit_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
