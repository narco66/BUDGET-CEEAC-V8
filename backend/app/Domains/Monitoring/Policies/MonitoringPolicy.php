<?php

namespace App\Domains\Monitoring\Policies;

use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Services\MonitoringService;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MonitoringPolicy
{
    public function __construct(private readonly MonitoringService $monitoring) {}

    public function viewAny(User $user): bool
    {
        return $user->holdsAny();
    }

    public function view(User $user, Model $model): bool
    {
        $papId = $model->getAttribute('pap_enrichment_id')
            ?? $model->indicator?->pap_enrichment_id;

        if ($papId === null) {
            return $this->viewAny($user);
        }

        return $this->monitoring->visible($user)->whereKey($papId)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function validate(User $user, IndicatorMeasurement $measurement): bool
    {
        // Le niveau de validation (responsable, hiérarchique, consolidation)
        // est contrôlé par MonitoringService::refusal().
        return $this->view($user, $measurement);
    }
}
