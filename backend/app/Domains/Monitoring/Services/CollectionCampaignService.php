<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Notifications\MonitoringAlert;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campagne de collecte : clôture d’une période et rappels J-7 / J-3
 * sur les indicateurs encore sans mesure.
 */
class CollectionCampaignService
{
    public function consolider(User $actor, MonitoringPeriod $period): MonitoringPeriod
    {
        if (! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages([
                'action' => 'Seul le Directeur du Budget consolide une période de collecte.',
            ]);
        }
        if ($period->status === 'consolidee') {
            return $period;
        }
        $ouvertes = IndicatorMeasurement::query()
            ->where('monitoring_period_id', $period->id)
            ->whereNull('superseded_at')
            ->whereNotIn('status', ['valide', 'consolide', 'rejete'])
            ->exists();
        if ($ouvertes) {
            throw ValidationException::withMessages([
                'periode' => 'Des mesures de cette période ne sont pas encore validées.',
            ]);
        }

        $period->forceFill([
            'status' => 'consolidee',
            'consolidated_at' => now(),
            'consolidated_by' => $actor->id,
        ])->save();

        return $period->fresh();
    }

    /**
     * @return array{j7: int, j3: int}
     */
    public function relancerIndicateurs(): array
    {
        $counts = ['j7' => 0, 'j3' => 0];
        foreach ([7 => 'j7', 3 => 'j3'] as $horizon => $key) {
            $periods = MonitoringPeriod::query()
                ->where('status', 'ouverte')
                ->whereDate('closes_on', today()->addDays($horizon))
                ->get();
            foreach ($periods as $period) {
                Indicator::query()->where('status', 'actif')->orderBy('id')->each(function (Indicator $indicator) use ($period, $horizon, $key, &$counts): void {
                    $renseigne = IndicatorMeasurement::query()
                        ->where('indicator_id', $indicator->id)
                        ->where('monitoring_period_id', $period->id)
                        ->whereNull('superseded_at')
                        ->exists();
                    if ($renseigne) {
                        return;
                    }
                    $inserted = DB::table('indicator_collection_reminders')->insertOrIgnore([
                        'indicator_id' => $indicator->id,
                        'monitoring_period_id' => $period->id,
                        'horizon' => $horizon,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if ($inserted === 0) {
                        return;
                    }
                    $this->notify($indicator, $period, $horizon);
                    $counts[$key]++;
                });
            }
        }

        return $counts;
    }

    private function notify(Indicator $indicator, MonitoringPeriod $period, int $horizon): void
    {
        $message = "Indicateur {$indicator->code} à renseigner avant le {$period->closes_on?->toDateString()} (J-{$horizon}).";
        $lien = '/suivi/indicateurs';
        if ($indicator->responsible_user_id !== null) {
            User::query()->whereKey($indicator->responsible_user_id)->each(
                fn (User $user) => $user->notify(new MonitoringAlert($message, $lien))
            );

            return;
        }
        if (filled($indicator->responsible_role)) {
            app(RoleHolders::class)->query((string) $indicator->responsible_role)->each(
                fn (User $user) => $user->notify(new MonitoringAlert($message, $lien))
            );
        }
    }
}
