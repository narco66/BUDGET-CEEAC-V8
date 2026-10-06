<?php

namespace App\Domains\Budget\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Needs\Enums\EbStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Source unique des soldes d’une ligne budgétaire (CDC §7.1, §27, PREP-006).
 * Les formules sont définies ici et réutilisées par les écrans, l’API et
 * les exports.
 *
 *  - révisé           = voté + ajustements + ouvertures/reports/transferts entrants − annulations/transferts sortants
 *  - engagé           = engagements actifs, nets des dégagements
 *  - liquidé          = liquidations visées (brut) + compléments visés
 *  - ordonnancé       = ordres signés
 *  - payé             = exécutions bancaires non rejetées
 *  - disponible       = révisé − gelé − engagé − réservé (EB en circuit)
 *  - reste à engager  = révisé − engagé
 *  - reste à liquider = engagé − liquidé
 *  - reste à ordonnancer = liquidé − ordonnancé
 *  - reste à payer    = ordonnancé − payé
 */
class BudgetBalanceService
{
    /**
     * @param  iterable<int>  $lineIds
     * @return array<int, array{initial: int, revise: int, gele: int, reserve: int, engage: int, liquide: int, ordonnance: int, paye: int, disponible: int, reste_a_engager: int, reste_a_liquider: int, reste_a_ordonnancer: int, reste_a_payer: int, taux_engagement: float, taux_liquidation: float, taux_paiement: float}>
     */
    public function forLines(iterable $lineIds): array
    {
        $ids = collect($lineIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $lines = BudgetLine::query()->whereKey($ids->all())->get(['id', 'montant_vote', 'ajustements'])->keyBy('id');
        $movements = DB::table('credit_movements')
            ->whereIn('budget_line_id', $ids)
            ->groupBy('budget_line_id', 'kind')
            ->selectRaw('budget_line_id, kind, SUM(amount) as total')
            ->get()
            ->groupBy('budget_line_id');

        $reserve = $this->sumBy(DB::table('eb_imputations')
            ->join('expression_besoins', 'expression_besoins.id', '=', 'eb_imputations.expression_besoin_id')
            ->whereIn('eb_imputations.budget_line_id', $ids)
            ->whereIn('expression_besoins.status', EbStatus::reserving())
            ->groupBy('eb_imputations.budget_line_id')
            ->selectRaw('eb_imputations.budget_line_id as line_id, SUM(eb_imputations.montant) as total'));

        $engage = $this->sumBy(DB::table('engagements')
            ->whereIn('budget_line_id', $ids)
            ->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])
            ->groupBy('budget_line_id')
            ->selectRaw('budget_line_id as line_id, SUM(montant - montant_degage) as total'));

        $liquide = $this->sumBy($this->visedLiquidations($ids)
            ->groupBy('engagements.budget_line_id')
            ->selectRaw('engagements.budget_line_id as line_id, SUM(liquidations.montant_brut) as total'));

        $complements = $this->sumBy($this->visedLiquidations($ids)
            ->join('liquidation_rectifications', 'liquidation_rectifications.liquidation_id', '=', 'liquidations.id')
            ->where('liquidation_rectifications.kind', 'complementaire')
            ->groupBy('engagements.budget_line_id')
            ->selectRaw('engagements.budget_line_id as line_id, SUM(liquidation_rectifications.amount) as total'));

        $ordonnance = $this->sumBy(DB::table('ordonnancements')
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $ids)
            ->whereIn('ordonnancements.status', $this->signedStatuses())
            ->groupBy('engagements.budget_line_id')
            ->selectRaw("engagements.budget_line_id as line_id, SUM(CASE WHEN ordonnancements.nature = 'rectificatif' THEN -ordonnancements.montant ELSE ordonnancements.montant END) as total"));

        $paye = $this->sumBy(DB::table('paiement_executions')
            ->join('paiements', 'paiements.id', '=', 'paiement_executions.paiement_id')
            ->join('ordonnancements', 'ordonnancements.id', '=', 'paiements.ordonnancement_id')
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $ids)
            ->where('paiement_executions.status', PaiementExecution::EXECUTEE)
            ->groupBy('engagements.budget_line_id')
            ->selectRaw('engagements.budget_line_id as line_id, SUM(paiement_executions.montant) as total'));

        $balances = [];
        foreach ($ids as $id) {
            $line = $lines->get($id);
            if ($line === null) {
                continue;
            }

            $kinds = collect($movements->get($id, []))->pluck('total', 'kind')->map(fn ($total) => (int) $total);
            $initial = (int) $line->montant_vote;
            $revise = $initial + (int) $line->ajustements
                + $kinds->only(['ouverture', 'report', 'transfert_entrant'])->sum()
                - $kinds->only(['annulation', 'transfert_sortant'])->sum();
            $gele = max(0, (int) $kinds->get('gel', 0) - (int) $kinds->get('degel', 0));
            $engageLine = $engage[$id] ?? 0;
            $liquideLine = ($liquide[$id] ?? 0) + ($complements[$id] ?? 0);
            $ordonnanceLine = $ordonnance[$id] ?? 0;
            $payeLine = $paye[$id] ?? 0;
            $reserveLine = $reserve[$id] ?? 0;

            $balances[$id] = [
                'initial' => $initial,
                'revise' => $revise,
                'gele' => $gele,
                'reserve' => $reserveLine,
                'engage' => $engageLine,
                'liquide' => $liquideLine,
                'ordonnance' => $ordonnanceLine,
                'paye' => $payeLine,
                'disponible' => $revise - $gele - $engageLine - $reserveLine,
                'reste_a_engager' => $revise - $engageLine,
                'reste_a_liquider' => $engageLine - $liquideLine,
                'reste_a_ordonnancer' => $liquideLine - $ordonnanceLine,
                'reste_a_payer' => $ordonnanceLine - $payeLine,
                'taux_engagement' => $this->rate($engageLine, $revise),
                'taux_liquidation' => $this->rate($liquideLine, $revise),
                'taux_paiement' => $this->rate($payeLine, $revise),
            ];
        }

        return $balances;
    }

    /**
     * @return array{initial: int, revise: int, gele: int, reserve: int, engage: int, liquide: int, ordonnance: int, paye: int, disponible: int, reste_a_engager: int, reste_a_liquider: int, reste_a_ordonnancer: int, reste_a_payer: int, taux_engagement: float, taux_liquidation: float, taux_paiement: float}
     */
    public function forLine(BudgetLine $line): array
    {
        return $this->forLines([$line->id])[$line->id];
    }

    /**
     * @param  Collection<int, int>  $ids
     */
    private function visedLiquidations(Collection $ids): Builder
    {
        return DB::table('liquidations')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $ids)
            ->whereNotNull('liquidations.visa_reference')
            ->whereNotIn('liquidations.status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value]);
    }

    /**
     * @return list<string>
     */
    private function signedStatuses(): array
    {
        return collect(OrdonnancementStatus::cases())
            ->filter(fn (OrdonnancementStatus $status) => $status->signed())
            ->map(fn (OrdonnancementStatus $status) => $status->value)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function sumBy(Builder $query): array
    {
        return $query->get()->mapWithKeys(fn ($row) => [(int) $row->line_id => (int) $row->total])->all();
    }

    private function rate(int $value, int $base): float
    {
        return $base > 0 ? round($value * 100 / $base, 2) : 0.0;
    }
}
