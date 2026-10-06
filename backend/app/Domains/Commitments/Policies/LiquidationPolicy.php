<?php

namespace App\Domains\Commitments\Policies;

use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Services\LiquidationWorkflow;
use App\Models\User;

class LiquidationPolicy
{
    public function __construct(private readonly LiquidationWorkflow $workflow) {}

    public function viewAny(User $user): bool
    {
        return $user->holdsAny();
    }

    public function view(User $user, Liquidation $liquidation): bool
    {
        $unitId = $liquidation->engagement?->expressionBesoin?->organization_unit_id;

        return $this->viewAny($user) && $user->seesOrganization($unitId);
    }

    public function certify(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isInitiator($user, $liquidation);
    }

    public function invoice(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isInitiator($user, $liquidation);
    }

    public function submit(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isInitiator($user, $liquidation);
    }

    public function requestDuplicate(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isInitiator($user, $liquidation);
    }

    public function sendBack(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isController($user, $liquidation);
    }

    public function complement(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isController($user, $liquidation);
    }

    public function reject(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isController($user, $liquidation);
    }

    public function vise(User $user, Liquidation $liquidation): bool
    {
        return $this->workflow->isController($user, $liquidation);
    }

    public function rectify(User $user, Liquidation $liquidation): bool
    {
        return $user->holds('controleur_financier')
            && $liquidation->status === LiquidationStatus::TransformeeOrdonnancement;
    }

    public function pdf(User $user, Liquidation $liquidation): bool
    {
        return $this->view($user, $liquidation) && $liquidation->visa_reference !== null;
    }
}
