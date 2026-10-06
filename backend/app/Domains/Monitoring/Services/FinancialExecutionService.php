<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;

/**
 * Exécution financière vue du Suivi-Évaluation. Aucun montant n’est recalculé
 * ici : tout provient de BudgetBalanceService, source unique des soldes de la
 * chaîne de dépense (CDC §27, description S&E §6 et §21). Ce service ne fait
 * que traduire ces soldes dans le vocabulaire S&E et dériver les taux.
 */
class FinancialExecutionService
{
    /**
     * @var array<int, array<string, int|float>>
     */
    private array $balances = [];

    public function __construct(
        private readonly BudgetBalanceService $balanceService,
        private readonly IndicatorCalculationService $rates,
    ) {}

    /**
     * Charge en une passe les soldes de plusieurs lignes (tableaux de bord).
     *
     * @param  iterable<int>  $lineIds
     */
    public function prime(iterable $lineIds): void
    {
        $missing = collect($lineIds)->filter()->map(fn ($id) => (int) $id)->unique()
            ->reject(fn (int $id) => array_key_exists($id, $this->balances));
        if ($missing->isNotEmpty()) {
            $this->balances = $this->balanceService->forLines($missing) + $this->balances;
        }
    }

    /**
     * @return array<string, int|float|null>
     */
    public function forLine(BudgetLine $line): array
    {
        $this->prime([$line->id]);
        $balance = $this->balances[$line->id];
        $revise = (int) $balance['revise'];
        $engage = (int) $balance['engage'];
        $liquide = (int) $balance['liquide'];
        $ordonnance = (int) $balance['ordonnance'];
        $paye = (int) $balance['paye'];

        return [
            'budget_initial' => (int) $balance['initial'],
            'budget_revise' => $revise,
            'engage' => $engage,
            'liquide' => $liquide,
            'ordonnance' => $ordonnance,
            'paye' => $paye,
            'disponible' => (int) $balance['disponible'],
            'reste_a_engager' => (int) $balance['reste_a_engager'],
            'reste_a_liquider' => (int) $balance['reste_a_liquider'],
            'reste_a_ordonnancer' => (int) $balance['reste_a_ordonnancer'],
            'reste_a_payer' => (int) $balance['reste_a_payer'],
            'taux_engagement' => $this->rates->rate($engage, $revise),
            'taux_liquidation' => $this->rates->rate($liquide, $engage),
            'taux_ordonnancement' => $this->rates->rate($ordonnance, $liquide),
            'taux_paiement' => $this->rates->rate($paye, $ordonnance),
            'taux_execution' => $this->rates->rate($paye, $revise),
        ];
    }

    /**
     * Les soldes sont mémorisés pour la durée d’un traitement ; une écriture
     * financière dans le même processus doit les invalider.
     */
    public function forget(): void
    {
        $this->balances = [];
    }
}
