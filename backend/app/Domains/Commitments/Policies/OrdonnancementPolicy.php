<?php

namespace App\Domains\Commitments\Policies;

use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Models\User;

class OrdonnancementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsAny();
    }

    public function view(User $user, Ordonnancement $ordonnancement): bool
    {
        $unitId = $ordonnancement->liquidation?->engagement?->expressionBesoin?->organization_unit_id;

        return $this->viewAny($user) && $user->seesOrganization($unitId);
    }

    public function sign(User $user, Ordonnancement $ordonnancement): bool
    {
        return $user->holds((string) $ordonnancement->ordonnateur_role)
            && $ordonnancement->status === OrdonnancementStatus::ASigner;
    }

    public function sendBack(User $user, Ordonnancement $ordonnancement): bool
    {
        return $this->sign($user, $ordonnancement);
    }

    public function reject(User $user, Ordonnancement $ordonnancement): bool
    {
        return $this->sign($user, $ordonnancement);
    }

    public function reprendre(User $user, Ordonnancement $ordonnancement): bool
    {
        return $user->holds((string) $ordonnancement->ordonnateur_role)
            && $ordonnancement->status === OrdonnancementStatus::TransmissionErreur;
    }
}
