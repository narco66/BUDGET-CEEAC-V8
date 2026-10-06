<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\OrganizationAssignment;
use App\Domains\Organization\Models\OrganizationPosition;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Models\OrganizationVersion;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    /**
     * @var array<string, string>
     */
    public const KINDS = [
        'communaute' => 'Communauté',
        'commission' => 'Commission',
        'departement' => 'Département',
        'direction' => 'Direction',
        'cabinet' => 'Cabinet',
        'service' => 'Service',
        'bureau' => 'Bureau',
    ];

    /**
     * @return array<string, mixed>
     */
    public function arbre(): array
    {
        $units = OrganizationUnit::query()->orderBy('sort_order')->orderBy('sigle')->get();
        $grouped = $units->groupBy(fn (OrganizationUnit $unit): string => (string) ($unit->parent_id ?? 'root'));

        $build = function (string $parent) use (&$build, $grouped): array {
            return ($grouped->get($parent) ?? collect())->map(function (OrganizationUnit $unit) use (&$build): array {
                return $this->ligne($unit) + ['enfants' => $build((string) $unit->id)];
            })->values()->all();
        };

        return $build('root');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rechercher(array $filters): array
    {
        $terme = trim((string) ($filters['q'] ?? ''));

        return OrganizationUnit::query()
            ->with('parent')
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when(array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null && $filters['parent_id'] !== '', fn ($query) => $query->where('parent_id', $filters['parent_id']))
            ->when($terme !== '', function ($query) use ($terme) {
                $like = '%'.$terme.'%';
                $query->where(fn ($inner) => $inner->where('sigle', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->orderBy('sort_order')
            ->orderBy('sigle')
            ->limit(200)
            ->get()
            ->map(fn (OrganizationUnit $unit): array => $this->ligne($unit) + ['parent' => $unit->parent?->sigle])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function fiche(OrganizationUnit $unit): array
    {
        $unit->load(['parent', 'children', 'version']);
        $responsable = $this->responsable($unit);

        return $this->ligne($unit) + [
            'description' => $unit->description,
            'parent_id' => $unit->parent_id,
            'parent' => $unit->parent === null ? null : ['id' => $unit->parent->id, 'sigle' => $unit->parent->sigle, 'nom' => $unit->parent->name],
            'date_effet' => $unit->effective_on?->toDateString() ?? $unit->version?->effective_on?->toDateString(),
            'version' => $unit->version?->code,
            'chemin' => $this->ancetres($unit),
            'enfants' => $unit->children->map(fn (OrganizationUnit $child): array => $this->ligne($child))->values(),
            'responsable' => $responsable,
            'affectations' => $this->affectations($unit),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enfants(OrganizationUnit $unit): array
    {
        return $unit->children()->get()->map(fn (OrganizationUnit $child): array => $this->ligne($child))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ancetres(OrganizationUnit $unit): array
    {
        $chemin = [];
        $cursor = $unit->parent;
        $guard = 0;
        while ($cursor !== null && $guard < 30) {
            array_unshift($chemin, $this->ligne($cursor));
            $cursor = $cursor->parent;
            $guard++;
        }

        return $chemin;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function responsable(OrganizationUnit $unit): ?array
    {
        $cursor = $unit;
        $guard = 0;
        while ($cursor !== null && $guard < 30) {
            $assignment = OrganizationAssignment::query()
                ->with(['user', 'position'])
                ->where('organization_unit_id', $cursor->id)
                ->where('statut', 'active')
                ->whereHas('position', fn ($query) => $query->where('is_active', true))
                ->get()
                ->sortBy(fn (OrganizationAssignment $row): int => (int) $row->position?->rank)
                ->first();
            if ($assignment !== null) {
                return [
                    'utilisateur_id' => $assignment->user_id,
                    'nom' => $assignment->user?->name,
                    'fonction' => $assignment->position?->label,
                    'fonction_code' => $assignment->position?->code,
                    'structure' => $cursor->sigle,
                    'debut' => $assignment->starts_on?->toDateString(),
                ];
            }
            $cursor = $cursor->parent;
            $guard++;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function occupants(int $unitId, string $positionCode): array
    {
        return OrganizationAssignment::query()
            ->where('organization_unit_id', $unitId)
            ->where('statut', 'active')
            ->whereHas('position', fn ($query) => $query->where('code', $positionCode)->where('is_active', true))
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function codeFonctionDuRole(string $role): ?string
    {
        return match ($role) {
            'directeur', 'directeur_budget' => 'directeur',
            'commissaire' => 'commissaire',
            'secretaire_general' => 'secretaire_general',
            'expert_budget' => 'expert',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creer(User $actor, array $data): OrganizationUnit
    {
        $parent = $this->parent((int) ($data['parent_id'] ?? 0));
        $unit = new OrganizationUnit;
        $this->assertAcyclic($unit, $parent?->id);
        $unit->fill([
            'parent_id' => $parent?->id,
            'sigle' => $this->sigle((string) $data['sigle']),
            'name' => $data['name'],
            'kind' => $this->kind((string) $data['kind']),
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_technical' => (bool) ($data['is_technical'] ?? false),
            'is_active' => true,
            'effective_on' => $data['effective_on'] ?? null,
            'version_id' => OrganizationVersion::query()->where('statut', 'publie')->value('id'),
        ]);
        $unit->save();
        FinancialAudit::record($actor, 'structure_creee', 'organization_unit', (string) $unit->id, null, $this->trace($unit));

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifier(User $actor, OrganizationUnit $unit, array $data): OrganizationUnit
    {
        $before = $this->trace($unit);
        $parentId = array_key_exists('parent_id', $data) ? ($data['parent_id'] === null || $data['parent_id'] === '' ? null : (int) $data['parent_id']) : $unit->parent_id;
        if ($parentId !== null) {
            $this->parent($parentId);
        }
        $this->assertAcyclic($unit, $parentId);
        $unit->fill([
            'parent_id' => $parentId,
            'name' => $data['name'] ?? $unit->name,
            'kind' => isset($data['kind']) ? $this->kind((string) $data['kind']) : $unit->kind,
            'description' => $data['description'] ?? $unit->description,
            'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $unit->sort_order,
            'is_technical' => array_key_exists('is_technical', $data) ? (bool) $data['is_technical'] : $unit->is_technical,
            'effective_on' => $data['effective_on'] ?? $unit->effective_on,
        ]);
        $unit->save();
        FinancialAudit::record($actor, 'structure_modifiee', 'organization_unit', (string) $unit->id, $before, $this->trace($unit));

        return $unit;
    }

    public function activer(User $actor, OrganizationUnit $unit, bool $active): OrganizationUnit
    {
        $before = ['actif' => $unit->is_active];
        $unit->forceFill(['is_active' => $active])->save();
        FinancialAudit::record($actor, $active ? 'structure_activee' : 'structure_desactivee', 'organization_unit', (string) $unit->id, $before, ['actif' => $active]);

        return $unit;
    }

    public function supprimer(User $actor, OrganizationUnit $unit): void
    {
        if ($unit->children()->exists() || $this->estUtilisee($unit)) {
            throw ValidationException::withMessages(['structure' => 'Cette structure a des enfants ou des dossiers. Elle se désactive, elle ne se supprime pas.']);
        }
        $before = $this->trace($unit);
        $id = $unit->id;
        $unit->delete();
        FinancialAudit::record($actor, 'structure_supprimee', 'organization_unit', (string) $id, $before, null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerFonction(User $actor, array $data, ?OrganizationPosition $position = null): OrganizationPosition
    {
        $payload = [
            'code' => strtolower((string) $data['code']),
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'rank' => (int) ($data['rank'] ?? 100),
            'compatible_kind' => isset($data['compatible_kind']) && $data['compatible_kind'] !== '' ? $this->kind((string) $data['compatible_kind']) : null,
            'parent_position_id' => $data['parent_position_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
        if ($position === null) {
            $position = OrganizationPosition::query()->create($payload);
            FinancialAudit::record($actor, 'fonction_creee', 'organization_position', (string) $position->id, null, $payload);

            return $position;
        }
        $before = $position->only(['code', 'label', 'rank', 'is_active']);
        $position->fill($payload)->save();
        FinancialAudit::record($actor, 'fonction_modifiee', 'organization_position', (string) $position->id, $before, $payload);

        return $position;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function affecter(User $actor, array $data): OrganizationAssignment
    {
        $user = User::query()->find($data['user_id']);
        $unit = OrganizationUnit::query()->find($data['organization_unit_id']);
        $position = OrganizationPosition::query()->where('is_active', true)->find($data['position_id']);
        if ($user === null || $unit === null || $position === null) {
            throw ValidationException::withMessages(['affectation' => 'Utilisateur, structure ou fonction inconnu.']);
        }
        OrganizationAssignment::query()
            ->where('user_id', $user->id)
            ->where('organization_unit_id', $unit->id)
            ->where('position_id', $position->id)
            ->where('statut', 'active')
            ->update(['statut' => 'terminee', 'ends_on' => now()->toDateString()]);
        $assignment = OrganizationAssignment::query()->create([
            'user_id' => $user->id,
            'organization_unit_id' => $unit->id,
            'position_id' => $position->id,
            'starts_on' => $data['starts_on'],
            'statut' => 'active',
            'motif' => $data['motif'] ?? null,
            'reference' => $data['reference'] ?? null,
        ]);
        if ((int) $user->organization_unit_id !== (int) $unit->id) {
            $user->forceFill(['organization_unit_id' => $unit->id])->save();
        }
        FinancialAudit::record($actor, 'affectation_creee', 'organization_assignment', (string) $assignment->id, null, [
            'user_id' => $user->id,
            'structure' => $unit->sigle,
            'fonction' => $position->code,
        ]);

        return $assignment;
    }

    public function cloturerAffectation(User $actor, OrganizationAssignment $assignment, ?string $motif): OrganizationAssignment
    {
        $assignment->forceFill([
            'statut' => 'terminee',
            'ends_on' => now()->toDateString(),
            'motif' => $motif ?? $assignment->motif,
        ])->save();
        FinancialAudit::record($actor, 'affectation_cloturee', 'organization_assignment', (string) $assignment->id, ['statut' => 'active'], ['statut' => 'terminee']);

        return $assignment;
    }

    public function publierSource(?User $actor = null): OrganizationVersion
    {
        return DB::transaction(function () use ($actor): OrganizationVersion {
            OrganizationVersion::query()->where('statut', 'publie')->where('code', '!=', 'ORG-2026')->update(['statut' => 'archive']);
            $version = OrganizationVersion::query()->updateOrCreate(['code' => 'ORG-2026'], [
                'label' => 'Organigramme de la Commission de la CEEAC',
                'document_reference' => 'Referentiel_organisationnel_Commission_CEEAC_2026.pdf',
                'effective_on' => '2026-06-01',
                'statut' => 'publie',
                'comment' => 'Source unique. Les structures absentes de ce document ne sont pas créées.',
                'published_at' => now(),
                'published_by' => $actor?->id,
            ]);
            /** @var list<array<string, mixed>> $tree */
            $tree = require database_path('data/organigramme-ceeac-2026.php');
            $order = 0;
            $this->walk($tree, null, $version, $order);
            $this->seedFonctions();
            $this->seedAffectations();
            if ($actor !== null) {
                FinancialAudit::record($actor, 'organigramme_publie', 'organization_version', (string) $version->id, null, ['code' => $version->code]);
            }

            return $version;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fonctions(): array
    {
        return OrganizationPosition::query()->orderBy('rank')->get()->map(fn (OrganizationPosition $position): array => [
            'id' => $position->id,
            'code' => $position->code,
            'libelle' => $position->label,
            'description' => $position->description,
            'rang' => $position->rank,
            'type' => $position->compatible_kind,
            'parent_id' => $position->parent_position_id,
            'actif' => $position->is_active,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function walk(array $nodes, ?OrganizationUnit $parent, OrganizationVersion $version, int &$order): void
    {
        foreach ($nodes as $node) {
            $order++;
            $unit = OrganizationUnit::query()->firstOrNew(['sigle' => $node['sigle']]);
            $unit->fill([
                'parent_id' => $parent?->id,
                'name' => $node['name'],
                'kind' => $node['kind'],
                'is_technical' => (bool) ($node['technical'] ?? false),
                'sort_order' => $order,
                'is_active' => $unit->exists ? $unit->is_active : true,
                'version_id' => $version->id,
                'effective_on' => $unit->effective_on ?? $version->effective_on,
            ]);
            $unit->save();
            $this->walk($node['children'] ?? [], $unit, $version, $order);
        }
    }

    private function seedFonctions(): void
    {
        $rows = [
            ['president', 'Président', 10, 'departement', null],
            ['vice_president', 'Vice-Président', 20, 'departement', 'president'],
            ['secretaire_general', 'Secrétaire Général', 30, 'departement', 'president'],
            ['commissaire', 'Commissaire', 40, 'departement', 'secretaire_general'],
            ['chef_cabinet', 'Chef de Cabinet', 45, 'cabinet', 'commissaire'],
            ['directeur', 'Directeur', 50, 'direction', 'commissaire'],
            ['chef_service', 'Chef de Service', 60, 'service', 'directeur'],
            ['chef_bureau', 'Chef de Bureau', 70, 'bureau', 'chef_service'],
            ['expert', 'Expert', 80, null, 'chef_service'],
            ['agent', 'Agent', 90, null, 'chef_service'],
        ];
        $ids = [];
        foreach ($rows as [$code, $label, $rank, $kind]) {
            $position = OrganizationPosition::query()->updateOrCreate(['code' => $code], [
                'label' => $label,
                'rank' => $rank,
                'compatible_kind' => $kind,
                'is_active' => true,
            ]);
            $ids[$code] = $position->id;
        }
        foreach ($rows as [$code, , , , $parent]) {
            OrganizationPosition::query()->where('code', $code)->update([
                'parent_position_id' => $parent === null ? null : ($ids[$parent] ?? null),
            ]);
        }
    }

    private function seedAffectations(): void
    {
        $positions = OrganizationPosition::query()->pluck('id', 'code');
        User::query()->whereNotNull('organization_unit_id')->each(function (User $user) use ($positions): void {
            $code = $this->codeFonctionDuRole((string) $user->role);
            if ($code === null || ! isset($positions[$code])) {
                return;
            }
            OrganizationAssignment::query()->firstOrCreate([
                'user_id' => $user->id,
                'organization_unit_id' => $user->organization_unit_id,
                'position_id' => $positions[$code],
                'statut' => 'active',
            ], [
                'starts_on' => '2026-01-01',
                'reference' => 'ORG-2026',
            ]);
        });
    }

    private function assertAcyclic(OrganizationUnit $unit, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($unit->exists && $parentId === $unit->id) {
            throw ValidationException::withMessages(['parent_id' => 'Une structure ne peut pas être son propre parent.']);
        }
        $cursor = OrganizationUnit::query()->find($parentId);
        $guard = 0;
        while ($cursor !== null && $guard < 40) {
            if ($unit->exists && $cursor->id === $unit->id) {
                throw ValidationException::withMessages(['parent_id' => 'Ce rattachement créerait un cycle hiérarchique.']);
            }
            $cursor = $cursor->parent_id === null ? null : OrganizationUnit::query()->find($cursor->parent_id);
            $guard++;
        }
    }

    private function parent(int $id): ?OrganizationUnit
    {
        if ($id === 0) {
            return null;
        }
        $parent = OrganizationUnit::query()->find($id);
        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Structure parente inconnue.']);
        }

        return $parent;
    }

    private function kind(string $kind): string
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Type de structure absent du référentiel officiel.']);
        }

        return $kind;
    }

    private function sigle(string $sigle): string
    {
        $sigle = strtoupper(trim($sigle));
        if ($sigle === '' || OrganizationUnit::query()->where('sigle', $sigle)->exists()) {
            throw ValidationException::withMessages(['sigle' => 'Ce code organisationnel existe déjà.']);
        }

        return $sigle;
    }

    private function estUtilisee(OrganizationUnit $unit): bool
    {
        $tables = [
            'users', 'budget_lines', 'budget_proposals', 'revenue_orders', 'revenue_forecasts',
            'engagements', 'workflow_tasks', 'gar_nodes', 'gar_node_units',
        ];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'organization_unit_id') && DB::table($table)->where('organization_unit_id', $unit->id)->exists()) {
                return true;
            }
        }

        return OrganizationAssignment::query()->where('organization_unit_id', $unit->id)->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function affectations(OrganizationUnit $unit): array
    {
        return OrganizationAssignment::query()->with(['user', 'position'])->where('organization_unit_id', $unit->id)->orderByDesc('id')->get()
            ->map(fn (OrganizationAssignment $row): array => [
                'id' => $row->id,
                'agent' => $row->user?->name,
                'fonction' => $row->position?->label,
                'debut' => $row->starts_on?->toDateString(),
                'fin' => $row->ends_on?->toDateString(),
                'statut' => $row->statut,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(OrganizationUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'code' => $unit->sigle,
            'nom' => $unit->name,
            'type' => $unit->kind,
            'type_libelle' => self::KINDS[$unit->kind] ?? $unit->kind,
            'ordre' => (int) $unit->sort_order,
            'actif' => (bool) $unit->is_active,
            'technique' => (bool) $unit->is_technical,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trace(OrganizationUnit $unit): array
    {
        return [
            'sigle' => $unit->sigle,
            'nom' => $unit->name,
            'type' => $unit->kind,
            'parent_id' => $unit->parent_id,
            'ordre' => (int) $unit->sort_order,
            'actif' => (bool) $unit->is_active,
        ];
    }
}
