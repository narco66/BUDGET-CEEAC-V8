<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Résolution centralisée du périmètre organisationnel effectif d’un compte.
 *
 * Ordre de décision :
 * 1. Un périmètre explicite enregistré dans access_scopes est toujours appliqué.
 * 2. Une fonction transversale documentée n’est pas limitée à une structure.
 * 3. Les autres comptes sont limités à leur structure affectée, élargie à la
 *    direction pour un directeur et au département pour un commissaire.
 */
class OrganizationScopeService
{
    /**
     * Fonctions transversales dont la mission exige une consultation
     * institutionnelle. Leur accès reste borné par les policies métier.
     *
     * @var list<string>
     */
    public const TRANSVERSAL = [
        'president',
        'vice_president',
        'secretaire_general',
        'ordonnateur',
        'controleur_financier',
        'controleur_financier_central',
        'comptable',
        'chef_comptable',
        'agent_comptable',
        'agent_comptable_central',
        'auditeur',
        'auditeur_interne',
        'administrateur_habilitations',
        'administrateur_fonctionnel',
        'expert_budget',
        'chef_budget',
        'directeur_budget',
    ];

    /**
     * @return list<int>|null null signifie « pas de restriction organisationnelle ».
     */
    public function forUser(User $user): ?array
    {
        $explicit = $this->explicit($user);
        if ($explicit !== null) {
            return $explicit;
        }

        if ($this->transversal($user)) {
            return null;
        }

        return $this->defaultScope($user);
    }

    /**
     * @return list<int>|null
     */
    private function explicit(User $user): ?array
    {
        $values = DB::table('access_scopes')
            ->where('user_id', $user->id)
            ->where('scope_type', 'organization_unit')
            ->pluck('scope_value');

        if ($values->isEmpty()) {
            return null;
        }

        return $values
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function transversal(User $user): bool
    {
        return array_intersect($user->heldRoleCodes(), self::TRANSVERSAL) !== [];
    }

    /**
     * @return list<int>
     */
    private function defaultScope(User $user): array
    {
        $unitId = $user->organization_unit_id;
        if ($unitId === null) {
            return [];
        }

        $units = OrganizationUnit::query()
            ->get(['id', 'parent_id', 'kind'])
            ->keyBy(fn (OrganizationUnit $unit): int => (int) $unit->id);

        $root = $units->get((int) $unitId);
        if (! $root instanceof OrganizationUnit) {
            return [(int) $unitId];
        }

        $role = $user->heldRoleCodes();
        if (array_intersect($role, ['commissaire']) !== []) {
            $root = $this->ancestor($root, $units, 'departement') ?? $root;
        } elseif (array_intersect($role, ['directeur', 'directeur_budget', 'directeur_cabinet']) !== []) {
            $root = $this->ancestor($root, $units, 'direction') ?? $root;
        }

        return $this->subtree($root, $units);
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $units
     */
    private function ancestor(OrganizationUnit $unit, $units, string $kind): ?OrganizationUnit
    {
        $cursor = $unit;
        $guard = 0;
        while ($cursor !== null && $guard < 32) {
            if ($cursor->kind === $kind) {
                return $cursor;
            }
            $cursor = $cursor->parent_id === null ? null : $units->get((int) $cursor->parent_id);
            $guard++;
        }

        return null;
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $units
     * @return list<int>
     */
    private function subtree(OrganizationUnit $root, $units): array
    {
        $ids = [(int) $root->id];
        $cursor = 0;
        while ($cursor < count($ids)) {
            $parentId = $ids[$cursor];
            foreach ($units as $unit) {
                if ((int) $unit->parent_id === $parentId) {
                    $ids[] = (int) $unit->id;
                }
            }
            $cursor++;
        }

        return array_values(array_unique($ids));
    }
}
