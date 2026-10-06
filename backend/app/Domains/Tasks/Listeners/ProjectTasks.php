<?php

namespace App\Domains\Tasks\Listeners;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Tasks\Services\TaskProjector;
use Illuminate\Database\Eloquent\Model;

/**
 * Projette la tâche dans la même transaction que la transition du dossier.
 * L’annulation de la transaction annule aussi la tâche.
 */
class ProjectTasks
{
    public function __construct(private readonly TaskProjector $projector) {}

    public function saved(Model $model): void
    {
        if (! $model->wasRecentlyCreated && ! $model->wasChanged(['status', 'workflow_step', 'due_on', 'montant', 'montant_net', 'ordonnateur_role'])) {
            return;
        }

        $type = self::entityType($model);
        if ($type === null || $model->getKey() === null) {
            return;
        }

        $this->projector->sync($type, (int) $model->getKey());
    }

    public static function entityType(Model $model): ?string
    {
        return match ($model::class) {
            ExpressionBesoin::class => 'expression_besoin',
            Engagement::class => 'engagement',
            Liquidation::class => 'liquidation',
            Ordonnancement::class => 'ordonnancement',
            Paiement::class => 'paiement',
            IndicatorMeasurement::class => 'indicator_measurement',
            PhysicalAchievement::class => 'physical_achievement',
            CorrectiveAction::class => 'corrective_action',
            SeRecommendation::class => 'se_recommendation',
            PerformanceReport::class => 'performance_report',
            default => null,
        };
    }
}
