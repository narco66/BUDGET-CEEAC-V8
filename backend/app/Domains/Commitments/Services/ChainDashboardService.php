<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Needs\Enums\EbStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de pilotage de la chaîne de dépense pour l’exercice en cours :
 * exécution financière (soldes de BudgetBalanceService), état des dossiers
 * à chaque maillon, évolution mensuelle, lignes sous tension et alertes.
 */
class ChainDashboardService
{
    private const MONTHS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    /** Seuil (en % du révisé) en dessous duquel le disponible d’une ligne est jugé tendu. */
    private const TENSION_RATE = 10.0;

    public function __construct(private readonly BudgetBalanceService $balances) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $exercice = Exercice::query()->whereIn('statut', ['executoire', 'ouvert'])->orderByDesc('annee')->first()
            ?? Exercice::query()->orderByDesc('annee')->first();

        if ($exercice === null) {
            return ['exercice' => null, 'execution' => null, 'maillons' => [], 'evolution' => [], 'lignes' => [], 'alertes' => []];
        }

        $lineIds = BudgetLine::query()->where('exercice_id', $exercice->id)->pluck('id');
        $balances = $this->balances->forLines($lineIds);
        $maillons = $this->stages($exercice, $lineIds->all());

        return [
            'exercice' => ['id' => $exercice->id, 'annee' => (int) $exercice->annee, 'statut' => $exercice->statut],
            'execution' => $this->execution($balances),
            'maillons' => $maillons,
            'evolution' => $this->evolution((int) $exercice->annee, $lineIds->all()),
            'lignes' => $this->lines($balances),
            'alertes' => $this->alerts($maillons, $lineIds->all()),
        ];
    }

    /**
     * @param  array<int, array<string, int|float>>  $balances
     * @return array<string, int|float>
     */
    private function execution(array $balances): array
    {
        $keys = ['initial', 'revise', 'gele', 'reserve', 'engage', 'liquide', 'ordonnance', 'paye', 'disponible', 'reste_a_engager', 'reste_a_liquider', 'reste_a_ordonnancer', 'reste_a_payer'];
        $totals = array_fill_keys($keys, 0);
        foreach ($balances as $balance) {
            foreach ($keys as $key) {
                $totals[$key] += (int) $balance[$key];
            }
        }
        $revise = $totals['revise'];

        return $totals + [
            'taux_engagement' => $this->rate($totals['engage'], $revise),
            'taux_liquidation' => $this->rate($totals['liquide'], $revise),
            'taux_ordonnancement' => $this->rate($totals['ordonnance'], $revise),
            'taux_paiement' => $this->rate($totals['paye'], $revise),
            'lignes' => count($balances),
        ];
    }

    /**
     * État des dossiers à chaque maillon : en cours, aboutis (transmis au
     * maillon suivant ou réglés), écartés (rejetés, annulés), en retard.
     *
     * @param  list<int>  $lineIds
     * @return list<array<string, mixed>>
     */
    private function stages(Exercice $exercice, array $lineIds): array
    {
        $eb = DB::table('expression_besoins')->where('exercice_id', $exercice->id);
        $eng = DB::table('engagements')->whereIn('engagements.budget_line_id', $lineIds);
        $liq = DB::table('liquidations')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $lineIds);
        $ord = DB::table('ordonnancements')
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $lineIds);
        $pay = DB::table('paiements')
            ->join('ordonnancements', 'ordonnancements.id', '=', 'paiements.ordonnancement_id')
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $lineIds);

        return [
            $this->stage('EB', 'Expressions de besoin', '/expressions-besoin', $eb, 'expression_besoins', 'expression_besoins.montant',
                [EbStatus::Transformee->value],
                [EbStatus::Rejetee->value, EbStatus::Annulee->value]),
            $this->stage('ENG', 'Engagements', '/engagements', $eng, 'engagements', 'engagements.montant - engagements.montant_degage',
                [EngagementStatus::Vise->value, EngagementStatus::TransformeLiquidation->value],
                [EngagementStatus::Rejete->value, EngagementStatus::Annule->value]),
            $this->stage('LIQ', 'Liquidations', '/liquidations', $liq, 'liquidations', 'COALESCE(liquidations.montant_brut, liquidations.montant)',
                [LiquidationStatus::Visee->value, LiquidationStatus::TransformeeOrdonnancement->value],
                [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value]),
            $this->stage('ORD', 'Ordonnancements', '/ordonnancements', $ord, 'ordonnancements', 'ordonnancements.montant',
                [OrdonnancementStatus::Signe->value, OrdonnancementStatus::TransmissionErreur->value, OrdonnancementStatus::TransformePaiement->value],
                [OrdonnancementStatus::Rejete->value]),
            $this->stage('PAY', 'Paiements', '/paiements', $pay, 'paiements', 'paiements.montant',
                [PaiementStatus::ARapprocher->value, PaiementStatus::Cloture->value],
                [PaiementStatus::Rejete->value]),
        ];
    }

    /**
     * @param  list<string>  $done
     * @param  list<string>  $dropped
     * @return array<string, mixed>
     */
    private function stage(string $code, string $label, string $to, Builder $base, string $table, string $amount, array $done, array $dropped): array
    {
        $closed = [...$done, ...$dropped];
        $rows = (clone $base)
            ->groupBy($table.'.status')
            ->selectRaw($table.'.status as status, COUNT(*) as nombre, SUM('.$amount.') as montant')
            ->get();
        $late = (clone $base)
            ->whereNotIn($table.'.status', $closed)
            ->whereNotNull($table.'.due_on')
            ->whereDate($table.'.due_on', '<', CarbonImmutable::today())
            ->count();

        $sum = fn (callable $filter, string $field) => (int) $rows->filter(fn ($row) => $filter($row->status))->sum($field);
        $isDone = fn (string $status) => in_array($status, $done, true);
        $isDropped = fn (string $status) => in_array($status, $dropped, true);
        $isOpen = fn (string $status) => ! in_array($status, $closed, true);

        return [
            'code' => $code,
            'libelle' => $label,
            'lien' => $to,
            'total' => (int) $rows->sum('nombre'),
            'montant' => (int) $rows->sum('montant'),
            'en_cours' => $sum($isOpen, 'nombre'),
            'montant_en_cours' => $sum($isOpen, 'montant'),
            'aboutis' => $sum($isDone, 'nombre'),
            'montant_aboutis' => $sum($isDone, 'montant'),
            'ecartes' => $sum($isDropped, 'nombre'),
            'en_retard' => $late,
        ];
    }

    /**
     * Cumuls mensuels de l’exercice : engagé (date de création de
     * l’engagement, net des dégagements) et payé (date de valeur des
     * exécutions bancaires). Les cumuls de fin rejoignent les soldes.
     *
     * @param  list<int>  $lineIds
     * @return list<array{mois: int, libelle: string, engage: int, paye: int}>
     */
    private function evolution(int $year, array $lineIds): array
    {
        $today = CarbonImmutable::today();
        $lastMonth = $year < $today->year ? 12 : ($year > $today->year ? 0 : $today->month);
        if ($lastMonth === 0) {
            return [];
        }

        $engagements = DB::table('engagements')
            ->whereIn('budget_line_id', $lineIds)
            ->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])
            ->get(['created_at', 'montant', 'montant_degage']);
        $payments = DB::table('paiement_executions')
            ->join('paiements', 'paiements.id', '=', 'paiement_executions.paiement_id')
            ->join('ordonnancements', 'ordonnancements.id', '=', 'paiements.ordonnancement_id')
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $lineIds)
            ->where('paiement_executions.status', PaiementExecution::EXECUTEE)
            ->get(['paiement_executions.date_valeur', 'paiement_executions.created_at', 'paiement_executions.montant']);

        $engaged = array_fill(1, 12, 0);
        foreach ($engagements as $row) {
            $engaged[$this->monthIndex($row->created_at, $year)] += (int) $row->montant - (int) $row->montant_degage;
        }
        $paid = array_fill(1, 12, 0);
        foreach ($payments as $row) {
            $paid[$this->monthIndex($row->date_valeur ?? $row->created_at, $year)] += (int) $row->montant;
        }

        $series = [];
        $engagedTotal = 0;
        $paidTotal = 0;
        for ($month = 1; $month <= $lastMonth; $month++) {
            $engagedTotal += $engaged[$month];
            $paidTotal += $paid[$month];
            $series[] = ['mois' => $month, 'libelle' => self::MONTHS[$month - 1], 'engage' => $engagedTotal, 'paye' => $paidTotal];
        }

        return $series;
    }

    /**
     * Mois de rattachement dans l’exercice : une date antérieure compte en
     * janvier, une date postérieure en décembre (aucun montant n’est perdu).
     */
    private function monthIndex(mixed $date, int $year): int
    {
        if ($date === null) {
            return 1;
        }
        $day = CarbonImmutable::parse((string) $date);
        if ($day->year < $year) {
            return 1;
        }

        return $day->year > $year ? 12 : $day->month;
    }

    /**
     * Lignes les plus engagées et lignes dont le disponible est tendu.
     *
     * @param  array<int, array<string, int|float>>  $balances
     * @return array{plus_engagees: list<array<string, mixed>>, tendues: int, en_depassement: int, seuil_tension: float}
     */
    private function lines(array $balances): array
    {
        $lines = BudgetLine::query()->whereKey(array_keys($balances))->get(['id', 'code', 'label'])->keyBy('id');
        $rows = collect($balances)->map(fn (array $balance, int $id) => [
            'id' => $id,
            'code' => $lines->get($id)?->code,
            'libelle' => $lines->get($id)?->label,
            'revise' => (int) $balance['revise'],
            'engage' => (int) $balance['engage'],
            'disponible' => (int) $balance['disponible'],
            'taux_engagement' => (float) $balance['taux_engagement'],
        ])->filter(fn (array $row) => $row['revise'] > 0);

        return [
            'plus_engagees' => $rows->sortByDesc('taux_engagement')->take(6)->values()->all(),
            'tendues' => $rows->filter(fn (array $row) => $row['disponible'] >= 0 && $this->rate($row['disponible'], $row['revise']) < self::TENSION_RATE)->count(),
            'en_depassement' => $rows->filter(fn (array $row) => $row['disponible'] < 0)->count(),
            'seuil_tension' => self::TENSION_RATE,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @param  list<int>  $lineIds
     * @return array<string, int>
     */
    private function alerts(array $stages, array $lineIds): array
    {
        $payments = DB::table('paiements')
            ->join('ordonnancements', 'ordonnancements.id', '=', 'paiements.ordonnancement_id')
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
            ->whereIn('engagements.budget_line_id', $lineIds);

        return [
            'en_retard' => (int) collect($stages)->sum('en_retard'),
            'transmissions_en_erreur' => DB::table('ordonnancements')
                ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
                ->join('engagements', 'engagements.id', '=', 'liquidations.engagement_id')
                ->whereIn('engagements.budget_line_id', $lineIds)
                ->where('ordonnancements.status', OrdonnancementStatus::TransmissionErreur->value)
                ->count(),
            'paiements_suspendus' => (clone $payments)->where('paiements.status', PaiementStatus::Suspendu->value)->count(),
            'rejets_bancaires' => (clone $payments)->where('paiements.status', PaiementStatus::RejeteBancaire->value)->count(),
            'a_rapprocher' => (clone $payments)->where('paiements.status', PaiementStatus::ARapprocher->value)->count(),
        ];
    }

    private function rate(int|float $value, int|float $base): float
    {
        return $base > 0 ? round($value / $base * 100, 1) : 0.0;
    }
}
