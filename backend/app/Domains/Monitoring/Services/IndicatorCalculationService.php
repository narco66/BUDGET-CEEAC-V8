<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Administration\Models\BusinessRule;

class IndicatorCalculationService
{
    public function attainment(string $direction, ?float $target, ?float $actual): ?float
    {
        if ($target === null || $actual === null || $direction === 'qualitatif') {
            return null;
        }

        return match ($direction) {
            'croissant' => $target == 0.0 ? null : round($actual / $target * 100, 2),
            'decroissant' => $actual == 0.0 ? null : round($target / $actual * 100, 2),
            'binaire' => $actual >= $target ? 100.0 : 0.0,
            default => null,
        };
    }

    public function physical(string $method, float $quantity, float $planned, bool $reached = false): float
    {
        $rate = match ($method) {
            'binaire' => $reached || $quantity >= 1 ? 100.0 : 0.0,
            'quantitative', 'jalon', 'livrable' => $planned == 0.0 ? 0.0 : $quantity / $planned * 100,
            default => 0.0,
        };

        return round($rate, 2);
    }

    /**
     * @param  list<array{progress: float, weight: int}>  $tasks
     */
    public function weighted(array $tasks): float
    {
        $weight = array_sum(array_column($tasks, 'weight'));
        if ($weight <= 0) {
            return 0.0;
        }
        $score = 0.0;
        foreach ($tasks as $task) {
            $score += $task['progress'] * $task['weight'];
        }

        return round($score / $weight, 2);
    }

    /**
     * Statut de performance (description S&E §20). Les seuils sont des règles
     * métier administrables ; les valeurs par défaut ne s’appliquent qu’en
     * l’absence de règle active.
     */
    public function performanceStatus(?float $rate): string
    {
        if ($rate === null) {
            return 'non_renseigne';
        }
        foreach ($this->thresholds() as $status => $minimum) {
            if ($rate >= $minimum) {
                return $status;
            }
        }

        return 'critique';
    }

    /**
     * @return array<string, float>
     */
    public function thresholds(): array
    {
        $defaults = ['atteint' => 100.0, 'en_bonne_voie' => 80.0, 'a_surveiller' => 60.0, 'en_retard' => 40.0];
        $thresholds = [];
        foreach ($defaults as $status => $default) {
            $thresholds[$status] = $this->rule('se_seuil_'.$status, $default);
        }
        arsort($thresholds);

        return $thresholds;
    }

    /**
     * Niveau d’un écart physique / financier (description S&E §25, maquette :
     * « ≤ 10 normal · 10–20 à surveiller · > 20 critique »).
     *
     * @return array{niveau: string, seuil_surveiller: float, seuil_critique: float}
     */
    public function gapLevel(float $gap): array
    {
        $watch = $this->rule('se_ecart_surveiller', 10.0);
        $critical = $this->rule('se_ecart_critique', 20.0);
        $absolute = abs($gap);

        return [
            'niveau' => $absolute > $critical ? 'critique' : ($absolute > $watch ? 'a_surveiller' : 'normal'),
            'seuil_surveiller' => $watch,
            'seuil_critique' => $critical,
        ];
    }

    /**
     * Appréciation d’un score composite (description S&E §41).
     */
    public function appreciation(?float $score): string
    {
        if ($score === null) {
            return 'non_renseigne';
        }

        return match (true) {
            $score >= $this->rule('se_appreciation_excellent', 90.0) => 'excellent',
            $score >= $this->rule('se_appreciation_satisfaisant', 75.0) => 'satisfaisant',
            $score >= $this->rule('se_appreciation_a_ameliorer', 60.0) => 'a_ameliorer',
            $score >= $this->rule('se_appreciation_insuffisant', 40.0) => 'insuffisant',
            default => 'critique',
        };
    }

    /**
     * Niveau d’une cellule de carte de chaleur (maquette, écran 1).
     */
    public function heat(?float $rate): string
    {
        if ($rate === null) {
            return 'non_renseigne';
        }

        return match (true) {
            $rate >= $this->rule('se_chaleur_conforme', 70.0) => 'conforme',
            $rate >= $this->rule('se_chaleur_surveiller', 50.0) => 'a_surveiller',
            default => 'critique',
        };
    }

    /**
     * @var array<string, mixed>|null
     */
    private ?array $rules = null;

    /**
     * Variation d’une période à l’autre au-delà de laquelle une valeur est
     * jugée peu plausible (contrôle de qualité de la saisie).
     */
    public function evolutionAlert(): float
    {
        return $this->rule('se_evolution_alerte', 20.0);
    }

    private function rule(string $code, float $default): float
    {
        $this->rules ??= BusinessRule::query()->where('code', 'like', 'se\_%')->where('active', true)->pluck('value', 'code')->all();
        $value = $this->rules[$code] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function rate(?int $part, ?int $whole): ?float
    {
        if ($whole === null || $whole === 0 || $part === null) {
            return null;
        }

        return round($part / $whole * 100, 2);
    }
}
