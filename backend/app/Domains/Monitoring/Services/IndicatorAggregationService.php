<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\Indicator;
use Illuminate\Support\Collection;

class IndicatorAggregationService
{
    /**
     * @param  Collection<int, Indicator>  $indicators
     * @return list<array<string, mixed>>
     */
    public function aggregate(Collection $indicators): array
    {
        $prepared = $indicators->map(function (Indicator $indicator) {
            $measurement = $indicator->measurements
                ->whereNull('superseded_at')
                ->whereIn('status', ['valide', 'consolide'])
                ->sortByDesc('id')
                ->first();

            return [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'label' => $indicator->label,
                'unite' => (string) $indicator->unit,
                'direction' => $indicator->direction,
                'methode' => $indicator->aggregation ?: 'non_aggregatable',
                'poids' => max(1, (int) $indicator->weight),
                'formule' => $indicator->formula,
                'mesure_id' => $measurement?->id ?? 0,
                'valeur' => $measurement?->value,
                'taux' => $measurement?->attainment_rate,
            ];
        });

        $singles = [];
        $buckets = [];
        foreach ($prepared as $row) {
            $isolated = $row['methode'] === 'non_aggregatable'
                || ($row['methode'] === 'custom_formula' && blank($row['formule']));
            if ($isolated) {
                $singles[] = $this->single($row);

                continue;
            }
            $key = $row['methode'].'|'.$row['unite'].'|'.$row['direction'].'|'.($row['formule'] ?? '');
            $buckets[$key][] = $row;
        }

        $aggregated = [];
        foreach ($buckets as $rows) {
            if (count($rows) < 2) {
                $singles[] = $this->single($rows[0]);

                continue;
            }
            $values = array_values(array_filter(array_column($rows, 'valeur'), fn ($value) => $value !== null));
            $method = $rows[0]['methode'];
            $result = $this->combine($method, $rows, $values);
            $aggregated[] = [
                'cle' => $rows[0]['unite'] !== '' ? $rows[0]['unite'] : $rows[0]['label'],
                'methode' => $method,
                'unite' => $rows[0]['unite'],
                'agrege' => $result !== null,
                'valeur' => $result,
                'formule' => $this->formula($method, $rows[0]['formule']),
                'indicateurs' => $rows,
            ];
        }

        return [...$aggregated, ...$singles];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function single(array $row): array
    {
        return [
            'cle' => $row['code'],
            'methode' => $row['methode'],
            'unite' => $row['unite'],
            'agrege' => false,
            'valeur' => $row['valeur'],
            'formule' => 'Valeur propre, sans agrégation',
            'indicateurs' => [$row],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<float|int|string>  $values
     */
    private function combine(string $method, array $rows, array $values): ?float
    {
        if ($values === [] && $method !== 'custom_formula') {
            return null;
        }
        $numbers = array_map(fn ($value) => (float) $value, $values);

        return match ($method) {
            'sum' => round(array_sum($numbers), 4),
            'average' => round(array_sum($numbers) / count($numbers), 4),
            'min' => round(min($numbers), 4),
            'max' => round(max($numbers), 4),
            'last_value' => round((float) collect($rows)->sortByDesc('mesure_id')->first()['valeur'], 4),
            'weighted_average' => $this->weighted($rows),
            'custom_formula' => $this->custom($rows),
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function weighted(array $rows): ?float
    {
        $weight = 0;
        $score = 0.0;
        foreach ($rows as $row) {
            if ($row['valeur'] === null) {
                continue;
            }
            $weight += $row['poids'];
            $score += (float) $row['valeur'] * $row['poids'];
        }

        return $weight > 0 ? round($score / $weight, 4) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function custom(array $rows): ?float
    {
        $formula = $rows[0]['formule'];
        if ($formula !== 'moyenne_taux') {
            return null;
        }
        $rates = array_values(array_filter(array_column($rows, 'taux'), fn ($value) => $value !== null));
        if ($rates === []) {
            return null;
        }

        return round(array_sum(array_map(fn ($value) => (float) $value, $rates)) / count($rates), 2);
    }

    private function formula(string $method, ?string $custom): string
    {
        return match ($method) {
            'sum' => 'Somme des valeurs de même unité et de même sens',
            'average' => 'Moyenne des valeurs de même unité et de même sens',
            'weighted_average' => 'Somme (valeur × poids) / somme des poids',
            'min' => 'Minimum des valeurs',
            'max' => 'Maximum des valeurs',
            'last_value' => 'Dernière valeur saisie du groupe',
            'custom_formula' => (string) $custom,
            default => 'Non agrégeable',
        };
    }
}
