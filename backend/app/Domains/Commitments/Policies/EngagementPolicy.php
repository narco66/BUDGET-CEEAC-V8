<?php

namespace App\Domains\Commitments\Policies;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Services\EngagementWorkflow;
use App\Models\User;

class EngagementPolicy
{
    public function __construct(private readonly EngagementWorkflow $workflow) {}

    public function viewAny(User $user): bool
    {
        return $user->holdsAny();
    }

    public function view(User $user, Engagement $engagement): bool
    {
        return $this->viewAny($user)
            && $user->seesOrganization($engagement->expressionBesoin?->organization_unit_id);
    }

    public function update(User $user, Engagement $engagement): bool
    {
        return $this->workflow->allows($user, $engagement) && $user->holds('expert_budget');
    }

    public function transmit(User $user, Engagement $engagement): bool
    {
        return $this->workflow->allows($user, $engagement)
            && $engagement->workflow_step !== 'controleur_financier';
    }

    public function sendBack(User $user, Engagement $engagement): bool
    {
        return $this->workflow->allows($user, $engagement)
            && $engagement->workflow_step !== 'expert_budget';
    }

    public function reject(User $user, Engagement $engagement): bool
    {
        return $this->workflow->allows($user, $engagement)
            && $user->holds('directeur_budget', 'controleur_financier');
    }

    public function vise(User $user, Engagement $engagement): bool
    {
        return $this->workflow->allows($user, $engagement)
            && $engagement->workflow_step === 'controleur_financier'
            && $user->porte('engagement.viser');
    }

    public function pdf(User $user, Engagement $engagement): bool
    {
        return $this->view($user, $engagement) && $engagement->visa_reference !== null;
    }
}
