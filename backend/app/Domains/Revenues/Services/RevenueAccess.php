<?php

namespace App\Domains\Revenues\Services;

use App\Models\User;

class RevenueAccess
{
    public function voir(?User $user): bool
    {
        return $user?->holdsAny() === true;
    }

    public function editer(?User $user): bool
    {
        return $user?->holds('expert_budget', 'directeur_budget', 'comptable') === true;
    }

    public function verifier(?User $user): bool
    {
        return $user?->holds('expert_budget') === true;
    }

    public function decider(?User $user): bool
    {
        return $user?->holds('directeur_budget') === true;
    }

    public function encaisser(?User $user): bool
    {
        return $user?->holds('comptable', 'agent_comptable', 'chef_comptable') === true;
    }

    public function rapprocher(?User $user): bool
    {
        return $user?->holds('chef_comptable', 'directeur_budget') === true;
    }

    public function relancer(?User $user): bool
    {
        return $user?->holds('expert_budget', 'comptable', 'directeur_budget', 'chef_comptable') === true;
    }

    /**
     * @return array<string, bool>
     */
    public function droits(?User $user): array
    {
        return [
            'voir' => $this->voir($user),
            'editer' => $this->editer($user),
            'verifier' => $this->verifier($user),
            'decider' => $this->decider($user),
            'encaisser' => $this->encaisser($user),
            'rapprocher' => $this->rapprocher($user),
            'relancer' => $this->relancer($user),
            'exporter' => $this->voir($user),
            'configurer' => $this->decider($user),
        ];
    }
}
