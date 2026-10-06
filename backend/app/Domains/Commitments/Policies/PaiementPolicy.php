<?php

namespace App\Domains\Commitments\Policies;

use App\Domains\Commitments\Models\Paiement;
use App\Models\User;

class PaiementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsAny();
    }

    public function view(User $user, Paiement $paiement): bool
    {
        $unitId = $paiement->ordonnancement?->liquidation?->engagement?->expressionBesoin?->organization_unit_id;

        return $this->viewAny($user) && $user->seesOrganization($unitId);
    }
}
