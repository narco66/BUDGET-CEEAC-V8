<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;

/**
 * Résout la direction et le département compétents en suivant parent_id.
 * Les préfixes de sigle ne sont pas découpés : le rattachement est celui de la base.
 */
class WorkflowActorResolver
{
    /** @var array<int, list<OrganizationUnit>> */
    private array $chains = [];

    /**
     * @return list<OrganizationUnit>
     */
    public function ascendants(OrganizationUnit $unit): array
    {
        if (isset($this->chains[$unit->id])) {
            return $this->chains[$unit->id];
        }

        $chain = [$unit];
        $cursor = $unit;
        $guard = 0;
        while ($cursor->parent_id !== null && $guard < 24) {
            $parent = $cursor->relationLoaded('parent')
                ? $cursor->parent
                : OrganizationUnit::query()->find($cursor->parent_id);
            if (! $parent instanceof OrganizationUnit) {
                break;
            }
            $chain[] = $parent;
            $cursor = $parent;
            $guard++;
        }

        return $this->chains[$unit->id] = $chain;
    }

    /**
     * Structures dont le directeur peut valider : le dossier et ses parents jusqu’à la direction incluse.
     *
     * @return list<int>
     */
    public function unitesDirection(OrganizationUnit $unit): array
    {
        return $this->jusqua($unit, fn (OrganizationUnit $node): bool => $node->kind === 'direction');
    }

    /**
     * Structures dont le commissaire peut valider : le dossier et ses parents jusqu’au département technique inclus.
     *
     * @return list<int>
     */
    public function unitesCommissaire(OrganizationUnit $unit): array
    {
        return $this->jusqua(
            $unit,
            fn (OrganizationUnit $node): bool => $node->kind === 'departement' && (bool) $node->is_technical,
        );
    }

    public function directionCompetente(OrganizationUnit $unit): ?OrganizationUnit
    {
        foreach ($this->ascendants($unit) as $node) {
            if ($node->kind === 'direction') {
                return $node;
            }
        }

        return null;
    }

    public function departementTechnique(OrganizationUnit $unit): ?OrganizationUnit
    {
        foreach ($this->ascendants($unit) as $node) {
            if ($node->kind === 'departement' && (bool) $node->is_technical) {
                return $node;
            }
        }

        return null;
    }

    public function rattacheAuSigle(User $user, string $sigle): bool
    {
        $racine = OrganizationUnit::query()->where('sigle', $sigle)->first();
        if ($racine === null || $user->organization_unit_id === null) {
            return false;
        }
        $unit = OrganizationUnit::query()->find($user->organization_unit_id);
        if (! $unit instanceof OrganizationUnit) {
            return false;
        }
        foreach ($this->ascendants($unit) as $node) {
            if ((int) $node->id === (int) $racine->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(OrganizationUnit): bool  $arret
     * @return list<int>
     */
    private function jusqua(OrganizationUnit $unit, callable $arret): array
    {
        $ids = [];
        $trouve = false;
        foreach ($this->ascendants($unit) as $node) {
            $ids[] = (int) $node->id;
            if ($arret($node)) {
                $trouve = true;
                break;
            }
        }

        return $trouve ? array_values(array_unique($ids)) : [(int) $unit->id];
    }
}
