<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord Suivi-Évaluation (maquette S&E, écran 1) et données
 * partagées avec la synthèse exécutive (écran 6). Les agrégats sont pondérés
 * par le budget révisé ; chaque chiffre provient des données validées et des
 * soldes de la chaîne de dépense.
 */
class PerformanceDashboardService
{
    /**
     * @var list<string>
     */
    public const STATUSES = ['non_demarree', 'planifiee', 'en_cours', 'en_retard', 'suspendue', 'bloquee', 'realisee', 'cloturee', 'annulee'];

    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly FinancialExecutionService $finances,
        private readonly IndicatorCalculationService $calculator,
        private readonly ActivitySheetService $sheets,
        private readonly PerformanceScoreService $scores,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters): array
    {
        $activities = $this->activities($user, $filters);
        $this->finances->prime($activities->pluck('budget_line_id'));
        $cards = $activities->map(fn (PapEnrichment $activity) => $this->monitoring->activityCard($activity))->values();
        if (! empty($filters['statut'])) {
            $keep = $cards->where('statut', $filters['statut'])->pluck('id');
            $activities = $activities->whereIn('id', $keep)->values();
            $cards = $cards->whereIn('id', $keep)->values();
        }

        $indicators = $this->sheets->indicatorRowsFor($activities);
        if (! empty($filters['periode'])) {
            $indicators = $this->indicatorsForPeriod($activities, (int) $filters['periode']);
        }
        $risks = SeRisk::query()->whereIn('pap_enrichment_id', $activities->pluck('id'))->get();
        $recommendations = SeRecommendation::query()
            ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $activities->pluck('id')))
            ->get();
        $scores = $this->scoresFor($activities, $cards, $risks);

        $attention = $this->attention($cards, $indicators);
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 8)));
        $page = max(1, (int) ($filters['attention_page'] ?? 1));
        $total = count($attention);
        $suivies = $cards->filter(fn (array $card): bool => (float) $card['physique'] > 0
            || (int) ($card['finances']['engage'] ?? 0) > 0
            || (int) ($card['finances']['paye'] ?? 0) > 0)->values();

        return [
            'filtres' => $this->filterOptions($user, $filters),
            'bandeau' => $this->strip($cards, $risks, $recommendations),
            'execution' => $this->execution($cards),
            'execution_suivie' => $this->execution($suivies) + ['activites' => $suivies->count()],
            'indicateurs' => $this->indicatorDistribution($indicators),
            'evolution' => $this->evolution($activities, (int) ($filters['exercice'] ?? today()->year), $suivies->pluck('id')),
            'structures' => $this->structures($cards, $indicators, $scores),
            'attention' => array_values(array_slice($attention, ($page - 1) * $perPage, $perPage)),
            'attention_meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
                'per_page' => $perPage,
            ],
            'activites' => $cards->map(fn (array $card) => collect($card)->except(['taches', 'finances'])->all() + ['score' => $scores[$card['id']]['score'] ?? null])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PapEnrichment>
     */
    public function activities(User $user, array $filters): Collection
    {
        return $this->monitoring->visible($user)
            ->with('budgetLine.exercice')
            ->when(! empty($filters['exercice']), fn ($query) => $query->whereHas('budgetLine.exercice', fn ($inner) => $inner->where('annee', (int) $filters['exercice'])))
            ->when(! empty($filters['structure']), fn ($query) => $query->whereHas('budgetLine.organizationUnit', fn ($inner) => $inner->whereKey((int) $filters['structure'])->orWhere('parent_id', (int) $filters['structure'])))
            ->when(! empty($filters['pilier']), fn ($query) => $query->where('pilier', $filters['pilier']))
            ->when(! empty($filters['programme']), fn ($query) => $query->where('axe', $filters['programme']))
            ->when(! empty($filters['type']), fn ($query) => $query->whereHas('budgetLine', fn ($inner) => $inner->where('nature', $filters['type'])))
            ->when(! empty($filters['responsable']), fn ($query) => $query->where('responsible_user_id', (int) $filters['responsable']))
            ->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  Collection<int, SeRisk>  $risks
     * @param  Collection<int, SeRecommendation>  $recommendations
     * @return array<string, mixed>
     */
    private function strip(Collection $cards, Collection $risks, Collection $recommendations): array
    {
        $late = $cards->where('statut', 'en_retard');
        $critical = $risks->where('status', '!=', 'clos')->filter(fn (SeRisk $risk) => $risk->criticite() === 'critique');
        $open = $recommendations->whereNotIn('status', SeRecommendation::FINAL);

        return [
            'activites' => $cards->count(),
            'pap' => $cards->where('nature', 'pap')->count(),
            'hors_pap' => $cards->where('nature', '!=', 'pap')->count(),
            'en_cours' => $cards->where('statut', 'en_cours')->count(),
            'terminees' => $cards->whereIn('statut', ['realisee', 'cloturee'])->count(),
            'en_retard' => $late->count(),
            'retard_plus_30' => $late->where('retard_jours', '>', 30)->count(),
            'bloquees' => $cards->whereIn('statut', ['bloquee', 'suspendue'])->count(),
            'risques_critiques' => $critical->count(),
            'risques_non_traites' => $critical->where('status', 'ouvert')->count(),
            'recommandations_en_retard' => $open->filter(fn (SeRecommendation $row) => $row->late())->count(),
            'recommandations_ouvertes' => $open->count(),
        ];
    }

    /**
     * Taux globaux pondérés par le budget révisé de chaque activité.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    public function execution(Collection $cards): array
    {
        $revise = (int) $cards->sum(fn (array $card) => (int) ($card['finances']['budget_revise'] ?? 0));
        $weighted = $revise > 0
            ? $cards->sum(fn (array $card) => (float) $card['physique'] * (int) ($card['finances']['budget_revise'] ?? 0)) / $revise
            : (float) $cards->avg('physique');
        $physical = round((float) $weighted, 2);
        $engaged = $this->calculator->rate((int) $cards->sum('finances.engage'), $revise) ?? 0.0;
        $paid = $this->calculator->rate((int) $cards->sum('finances.paye'), $revise) ?? 0.0;
        $gap = round($physical - $engaged, 2);
        $level = $this->calculator->gapLevel($gap);

        return [
            'physique' => $physical,
            'engage' => $engaged,
            'paye' => $paid,
            'budget_revise' => $revise,
            'montant_engage' => (int) $cards->sum('finances.engage'),
            'montant_paye' => (int) $cards->sum('finances.paye'),
            'ecart' => $gap,
            'niveau' => $level['niveau'],
            'sens' => $gap >= 0 ? 'physique_superieur' : 'financier_superieur',
            'seuils' => ['surveiller' => $level['seuil_surveiller'], 'critique' => $level['seuil_critique']],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $indicators
     * @return array<string, mixed>
     */
    public function indicatorDistribution(Collection $indicators): array
    {
        $order = ['atteint', 'en_bonne_voie', 'a_surveiller', 'en_retard', 'critique', 'non_renseigne'];
        $counts = collect($order)->mapWithKeys(fn (string $status) => [$status => $indicators->where('statut', $status)->count()]);
        $rates = $indicators->pluck('taux')->filter(fn ($rate) => $rate !== null);

        return [
            'total' => $indicators->count(),
            'repartition' => $counts->all(),
            'taux_moyen' => $rates->isEmpty() ? null : round((float) $rates->avg(), 2),
        ];
    }

    /**
     * Évolution mensuelle cumulée de l’exercice : avancement physique validé
     * à la fin de chaque mois et engagé net cumulé, rapportés au budget révisé.
     *
     * @param  Collection<int, PapEnrichment>  $activities
     * @param  Collection<int, int>|null  $suivieIds
     * @return list<array<string, mixed>>
     */
    public function evolution(Collection $activities, int $year, ?Collection $suivieIds = null): array
    {
        if ($activities->isEmpty()) {
            return [];
        }
        $lineIds = $activities->pluck('budget_line_id');
        $this->finances->prime($lineIds);
        $revisedByLine = $activities->mapWithKeys(fn (PapEnrichment $activity) => [$activity->id => (int) ($activity->budgetLine ? $this->finances->forLine($activity->budgetLine)['budget_revise'] : 0)]);
        $totalRevised = max(1, $revisedByLine->sum());
        $achievements = PhysicalAchievement::query()
            ->whereIn('pap_enrichment_id', $activities->pluck('id'))
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNotNull('validated_at')
            ->orderBy('validated_at')
            ->get(['pap_enrichment_id', 'pap_task_id', 'progress_percent', 'validated_at']);
        $engagements = DB::table('engagements')
            ->whereIn('budget_line_id', $lineIds)
            ->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])
            ->get(['id', 'budget_line_id', 'montant', 'created_at']);
        $releases = DB::table('engagement_degagements')->whereIn('engagement_id', $engagements->pluck('id'))->get(['engagement_id', 'montant', 'created_at']);
        $followed = $activities->whereIn('id', ($suivieIds ?? collect())->all());
        $followedRevised = (int) $followed->sum(fn (PapEnrichment $activity): int => (int) ($revisedByLine[$activity->id] ?? 0));
        $followedLines = $followed->pluck('budget_line_id')->map(fn (mixed $id): int => (int) $id)->all();

        $last = $year < today()->year ? 12 : ($year > today()->year ? 0 : today()->month);
        $months = [];
        for ($month = 1; $month <= $last; $month++) {
            $end = Carbon::create($year, $month, 1)->endOfMonth();
            $physical = $activities->sum(fn (PapEnrichment $activity) => $this->physicalAt($activity, $achievements, $end) * $revisedByLine[$activity->id]) / $totalRevised;
            $engagedRows = $engagements->filter(fn (object $row): bool => Carbon::parse($row->created_at)->lte($end));
            $engaged = (int) $engagedRows->sum('montant')
                - (int) $releases->filter(fn (object $row): bool => Carbon::parse($row->created_at)->lte($end))->sum('montant');
            $followedRows = $engagedRows->filter(fn (object $row): bool => in_array((int) $row->budget_line_id, $followedLines, true));
            $followedEngaged = (int) $followedRows->sum('montant')
                - (int) $releases->filter(fn (object $row): bool => $followedRows->pluck('id')->contains($row->engagement_id) && Carbon::parse($row->created_at)->lte($end))->sum('montant');
            $months[] = [
                'mois' => $end->format('Y-m'),
                'libelle' => ucfirst($end->locale('fr')->translatedFormat('M')),
                'physique' => round((float) $physical, 2),
                'engage' => round($engaged / $totalRevised * 100, 2),
                'physique_suivi' => $followedRevised > 0
                    ? round((float) ($followed->sum(fn (PapEnrichment $activity): float => $this->physicalAt($activity, $achievements, $end) * (int) ($revisedByLine[$activity->id] ?? 0)) / $followedRevised), 2)
                    : null,
                'engage_suivi' => $followedRevised > 0 ? round($followedEngaged / $followedRevised * 100, 2) : null,
            ];
        }

        return $months;
    }

    /**
     * @param  Collection<int, PhysicalAchievement>  $achievements
     */
    private function physicalAt(PapEnrichment $activity, Collection $achievements, Carbon $end): float
    {
        $mine = $achievements->where('pap_enrichment_id', $activity->id)->filter(fn (PhysicalAchievement $row) => $row->validated_at->lte($end));
        if ($activity->tasks->isNotEmpty()) {
            $weight = max(1, (int) $activity->tasks->sum('weight'));

            return $activity->tasks->sum(function (PapTask $task) use ($mine) {
                $last = $mine->where('pap_task_id', $task->id)->last();

                return (float) ($last?->progress_percent ?? 0) * (int) $task->weight;
            }) / $weight;
        }

        return (float) ($mine->whereNull('pap_task_id')->last()?->progress_percent ?? 0);
    }

    /**
     * Performance comparée des structures, classée par score.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  Collection<int, array<string, mixed>>  $indicators
     * @param  array<int, array<string, mixed>>  $scores
     * @return list<array<string, mixed>>
     */
    public function structures(Collection $cards, Collection $indicators, array $scores): array
    {
        return $cards->groupBy('structure_id')
            ->map(function (Collection $group) use ($indicators, $scores) {
                $first = $group->first();
                $execution = $this->execution($group);
                $rates = $indicators->whereIn('pap_enrichment_id', $group->pluck('id'))->pluck('taux')->filter(fn ($rate) => $rate !== null);
                $indicatorRate = $rates->isEmpty() ? null : round((float) $rates->avg(), 2);
                $late = $group->where('statut', 'en_retard')->count();
                $score = round((float) $group->map(fn (array $card) => $scores[$card['id']]['score'] ?? 0)->avg(), 1);
                $unit = OrganizationUnit::query()->with('parent')->find($first['structure_id']);

                return [
                    'structure_id' => $first['structure_id'],
                    'structure' => $unit ? ($unit->parent ? $unit->parent->sigle.' · '.$unit->name : $unit->sigle.' · '.$unit->name) : 'Non rattachée',
                    'physique' => $execution['physique'],
                    'physique_niveau' => $this->calculator->heat($execution['physique']),
                    'financier' => $execution['engage'],
                    'ecart' => abs($execution['ecart']),
                    'ecart_niveau' => $execution['niveau'],
                    'indicateurs' => $indicatorRate,
                    'indicateurs_niveau' => $this->calculator->heat($indicatorRate),
                    'retards' => $late,
                    'delais_niveau' => $late === 0 ? 'conforme' : ($late <= 2 ? 'a_surveiller' : 'critique'),
                    'score' => $score,
                    'appreciation' => $this->calculator->appreciation($score),
                    'activites' => $group->count(),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->map(fn (array $row, int $index) => ['rang' => $index + 1] + $row)
            ->all();
    }

    /**
     * Activités nécessitant une attention, par gravité décroissante : écart
     * critique, retard, indicateur sous les seuils.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  Collection<int, array<string, mixed>>  $indicators
     * @return list<array<string, mixed>>
     */
    public function attention(Collection $cards, Collection $indicators): array
    {
        return $cards->map(function (array $card) use ($indicators) {
            $worst = $indicators->where('pap_enrichment_id', $card['id'])->filter(fn (array $row) => in_array($row['statut'], ['en_retard', 'critique'], true))->sortBy('taux')->first();
            [$severity, $kind, $reason] = match (true) {
                $card['niveau_ecart'] === 'critique' => [3, 'ecart', ($card['ecart'] < 0 ? 'Financier > physique' : 'Physique > financier').' · '.round(abs($card['ecart'])).' pts'],
                $card['retard_jours'] > 0 => [2, 'retard', 'Retard de '.$card['retard_jours'].' jours'],
                $worst !== null => [1, 'indicateur', 'Indicateur à '.round((float) $worst['taux']).' % de la cible'],
                default => [0, null, null],
            };

            return $severity === 0 ? null : [
                'id' => $card['id'],
                'activite' => $card['activite'],
                'structure' => $card['structure'],
                'physique' => $card['physique'],
                'financier' => $card['financier'],
                'motif' => $reason,
                'type' => $kind,
                'gravite' => $severity,
                'ecart_id' => PerformanceVariance::query()->where('pap_enrichment_id', $card['id'])->where('status', '!=', 'clos')->latest('id')->value('id'),
            ];
        })->filter()->sortByDesc(fn (array $row) => [$row['gravite'], abs($row['physique'] - $row['financier'])])->values()->all();
    }

    /**
     * @param  Collection<int, PapEnrichment>  $activities
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  Collection<int, SeRisk>  $risks
     * @return array<int, array<string, mixed>>
     */
    public function scoresFor(Collection $activities, Collection $cards, Collection $risks): array
    {
        $indicators = Indicator::query()->whereIn('pap_enrichment_id', $activities->pluck('id'))->with('measurements')->get()->groupBy('pap_enrichment_id');
        $critical = PerformanceVariance::query()->whereIn('pap_enrichment_id', $activities->pluck('id'))->where('status', 'critique')->get()->groupBy('pap_enrichment_id');

        return $cards->mapWithKeys(fn (array $card) => [$card['id'] => $this->scores->score(
            $card,
            $indicators->get($card['id'], collect()),
            $risks->where('pap_enrichment_id', $card['id']),
            $critical->get($card['id'], collect())->count(),
        )])->all();
    }

    /**
     * @param  Collection<int, PapEnrichment>  $activities
     * @return Collection<int, array<string, mixed>>
     */
    private function indicatorsForPeriod(Collection $activities, int $periodId): Collection
    {
        return Indicator::query()
            ->whereIn('pap_enrichment_id', $activities->pluck('id'))
            ->with([
                'targets' => fn ($query) => $query->where('monitoring_period_id', $periodId)->with('period'),
                'measurements' => fn ($query) => $query->where('monitoring_period_id', $periodId)->whereIn('status', ['valide', 'consolide'])->whereNull('superseded_at')->with('period'),
            ])
            ->get()
            ->map(fn (Indicator $indicator) => $this->sheets->indicatorRow($indicator) + ['pap_enrichment_id' => $indicator->pap_enrichment_id]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterOptions(User $user, array $filters): array
    {
        $all = $this->monitoring->visible($user)->with('budgetLine.exercice', 'responsible')->get();

        return [
            'valeurs' => $filters,
            'exercices' => $all->pluck('budgetLine.exercice.annee')->filter()->unique()->sort()->values(),
            'periodes' => MonitoringPeriod::query()->orderBy('opens_on')->get(['id', 'code', 'label']),
            'departements' => OrganizationUnit::query()->where('kind', 'departement')->orderBy('sigle')->get(['id', 'sigle', 'name']),
            'programmes' => $all->pluck('axe')->filter()->unique()->sort()->values(),
            'piliers' => $all->pluck('pilier')->filter()->unique()->sort()->values(),
            'types' => ['pap' => 'PAP', 'hors_pap' => 'Hors PAP'],
            'statuts' => self::STATUSES,
            'responsables' => $all->pluck('responsible')->filter()->unique('id')->map(fn (User $row) => ['id' => $row->id, 'nom' => $row->name])->values(),
        ];
    }
}
