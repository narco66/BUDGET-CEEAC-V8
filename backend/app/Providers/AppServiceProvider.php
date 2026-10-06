<?php

namespace App\Providers;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Policies\EngagementPolicy;
use App\Domains\Commitments\Policies\LiquidationPolicy;
use App\Domains\Commitments\Policies\OrdonnancementPolicy;
use App\Domains\Commitments\Policies\PaiementPolicy;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeEvaluation;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Policies\MonitoringPolicy;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Policies\NeedPolicy;
use App\Domains\Tasks\Listeners\ProjectTasks;
use App\Shared\Notifications\JournaliserNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(NotificationSent::class, [JournaliserNotification::class, 'handle']);
        Gate::policy(ExpressionBesoin::class, NeedPolicy::class);
        Gate::policy(Engagement::class, EngagementPolicy::class);
        Gate::policy(Liquidation::class, LiquidationPolicy::class);
        Gate::policy(Ordonnancement::class, OrdonnancementPolicy::class);
        Gate::policy(Paiement::class, PaiementPolicy::class);
        foreach ([Indicator::class, IndicatorMeasurement::class, PhysicalAchievement::class, PerformanceVariance::class, CorrectiveAction::class, SeRisk::class, SeRecommendation::class, SeEvaluation::class] as $model) {
            Gate::policy($model, MonitoringPolicy::class);
        }

        foreach ([
            ExpressionBesoin::class,
            Engagement::class,
            Liquidation::class,
            Ordonnancement::class,
            Paiement::class,
            IndicatorMeasurement::class,
            PhysicalAchievement::class,
            CorrectiveAction::class,
            SeRecommendation::class,
            PerformanceReport::class,
        ] as $model) {
            $model::saved(fn ($saved) => app(ProjectTasks::class)->saved($saved));
        }
    }
}
