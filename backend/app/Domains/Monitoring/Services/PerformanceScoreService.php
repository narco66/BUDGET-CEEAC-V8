<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\SeReferential;
use Illuminate\Support\Collection;

class PerformanceScoreService
{
    /**
     * @param  array<string, mixed>  $card
     * @param  Collection<int, mixed>  $indicators
     * @param  Collection<int, mixed>  $risks
     * @return array<string, mixed>
     */
    public function score(array $card, Collection $indicators, Collection $risks, int $criticalVariances): array
    {
        $components = SeReferential::query()->where('kind', 'score')->where('active', true)->orderBy('id')->get();
        $version = (int) ($components->max('formula_version') ?: 1);
        $rates = [
            'physique' => $this->bound((float) ($card['physique'] ?? 0)),
            'financier' => $this->bound((float) ($card['paye_taux'] ?? $card['financier'] ?? 0)),
            'delai' => $this->delay($card['taches'] ?? []),
            'indicateurs' => $this->indicators($indicators),
            'risques' => $this->risks($risks),
            'qualite' => $this->bound(100 - ($criticalVariances * 25)),
        ];

        $lines = [];
        $total = 0.0;
        foreach ($components as $component) {
            $rate = $rates[$component->code] ?? 0.0;
            $points = round($rate * (float) $component->weight / 100, 2);
            $total += $points;
            $lines[] = [
                'code' => $component->code,
                'libelle' => $component->label,
                'poids' => (float) $component->weight,
                'taux' => $rate,
                'points' => $points,
                'formule' => $rate.' × '.$component->weight.' / 100',
            ];
        }

        return [
            'score' => round($total, 2),
            'appreciation' => app(IndicatorCalculationService::class)->appreciation(round($total, 2)),
            'formule' => 'Somme (taux de la composante × poids / 100)',
            'version' => $version,
            'composantes' => $lines,
        ];
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $tasks
     */
    private function delay(iterable $tasks): float
    {
        $dated = 0;
        $late = 0;
        foreach ($tasks as $task) {
            if (empty($task['fin'])) {
                continue;
            }
            $dated++;
            if ($task['fin'] < now()->toDateString() && (float) $task['avancement'] < 100) {
                $late++;
            }
        }

        return $dated === 0 ? 100.0 : $this->bound(100 - ($late / $dated * 100));
    }

    private function indicators(Collection $indicators): float
    {
        $rates = $indicators->flatMap(fn ($indicator) => $indicator->measurements)
            ->whereNull('superseded_at')
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNotNull('attainment_rate')
            ->pluck('attainment_rate');
        if ($rates->isEmpty()) {
            return 0.0;
        }

        return $this->bound((float) $rates->avg());
    }

    private function risks(Collection $risks): float
    {
        $critical = $risks->filter(fn ($risk) => $risk->criticite() === 'critique')->count();

        return $this->bound(100 - ($critical * 25));
    }

    private function bound(float $value): float
    {
        return round(min(100, max(0, $value)), 2);
    }
}
