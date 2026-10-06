<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeMilestone;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Données de la fiche activité 360° (maquette S&E, écran 2) : bandeau de six
 * indicateurs, physique et financier, tâches pondérées, indicateurs et jalons.
 * Tout est calculé à partir des données validées ; rien n’est saisi ici.
 */
class ActivitySheetService
{
    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly IndicatorCalculationService $calculator,
        private readonly PerformanceScoreService $scores,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sheet(PapEnrichment $activity): array
    {
        $activity->loadMissing(['budgetLine.organizationUnit', 'tasks', 'responsible', 'milestones']);
        $card = $this->monitoring->activityCard($activity);
        $indicators = $this->indicators($activity);
        $risks = SeRisk::query()->where('pap_enrichment_id', $activity->id)->get();
        $variances = PerformanceVariance::query()->where('pap_enrichment_id', $activity->id)->latest('id')->get();
        $score = $this->scores->score(
            $card,
            Indicator::query()->where('pap_enrichment_id', $activity->id)->with('measurements')->get(),
            $risks,
            $variances->where('status', 'critique')->count(),
        );
        $explained = $variances->firstWhere(fn (PerformanceVariance $row) => filled($row->explanation));

        return [
            'activite' => $card,
            'bandeau' => [
                'debut_prevu' => $card['date_debut'],
                'debut_reel' => $card['debut_reel'],
                'retard_demarrage' => $card['retard_demarrage'],
                'fin_prevue' => $card['date_fin'],
                'jours_restants' => $card['jours_restants'],
                'retard_jours' => $card['retard_jours'],
                'physique' => $card['physique'],
                'methode_physique' => $activity->tasks->isNotEmpty() ? 'pondéré' : 'déclaré',
                'financier' => $card['financier'],
                'indicateurs_atteints' => collect($indicators)->where('statut', 'atteint')->count(),
                'indicateurs_total' => count($indicators),
                'indicateurs_en_retard' => collect($indicators)->whereIn('statut', ['en_retard', 'critique'])->count(),
                'score' => $score['score'],
                'appreciation' => $score['appreciation'],
            ],
            'explication' => $explained ? [
                'texte' => $explained->explanation,
                'auteur' => $explained->explained_by ? User::query()->whereKey($explained->explained_by)->value('name') : null,
                'le' => $explained->explanation_received_at?->toDateString(),
                'causes' => $explained->cause_categories ?? array_filter([$explained->cause_category]),
                'ecart_id' => $explained->id,
            ] : null,
            'ecart_ouvert' => $variances->firstWhere(fn (PerformanceVariance $row) => $row->status !== 'clos')?->id,
            'taches' => $this->tasks($activity),
            'avancement_pondere' => $this->weightedTotal($activity),
            'indicateurs' => $indicators,
            'jalons' => $activity->milestones->map(fn (SeMilestone $milestone) => [
                'id' => $milestone->id,
                'libelle' => $milestone->label,
                'prevu' => $milestone->planned_on?->toDateString(),
                'reel' => $milestone->achieved_on?->toDateString(),
                'statut' => $milestone->status(),
                'preuve' => $milestone->proof_label,
            ])->values()->all(),
            'score' => $score,
            'risques' => $risks->map(fn (SeRisk $risk) => $risk->only(['id', 'reference', 'description', 'status']) + ['criticite' => $risk->criticite()])->values()->all(),
        ];
    }

    /**
     * Tâches et pondération : poids normalisé en %, réalisé validé, contribution
     * à l’avancement de l’activité et statut.
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(PapEnrichment $activity): array
    {
        $totalWeight = max(1, (int) $activity->tasks->sum('weight'));
        $latest = PhysicalAchievement::query()
            ->whereIn('pap_task_id', $activity->tasks->pluck('id'))
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNull('superseded_at')
            ->latest('validated_at')
            ->get()
            ->unique('pap_task_id')
            ->keyBy('pap_task_id');

        return $activity->tasks->map(function (PapTask $task) use ($totalWeight, $latest) {
            $share = round((int) $task->weight / $totalWeight * 100, 2);
            $progress = $task->progress_percent;
            $achievement = $latest->get($task->id);

            return [
                'id' => $task->id,
                'code' => $task->code ?: 'T'.$task->position,
                'libelle' => $task->label,
                'responsable' => $task->responsible_label,
                'poids' => $share,
                'unite' => $task->unit,
                'realise' => $achievement?->quantity,
                'prevu' => $achievement?->planned ?? $task->planned_quantity,
                'avancement' => $progress !== null ? (float) $progress : null,
                'contribution' => round((float) ($progress ?? 0) * $share / 100, 2),
                'statut' => $this->taskStatus($task),
                'debut' => $task->starts_on?->toDateString(),
                'fin' => $task->ends_on?->toDateString(),
                'debut_initial' => $task->baseline_starts_on?->toDateString(),
                'fin_initiale' => $task->baseline_ends_on?->toDateString(),
                'debut_reel' => $task->actual_start?->toDateString(),
                'fin_reelle' => $task->actual_end?->toDateString(),
                'depend_de' => $task->depends_on_id,
            ];
        })->values()->all();
    }

    /**
     * Statut d’une tâche : atteint à 100 % ; sans réalisation validée, « en
     * retard » si elle aurait dû démarrer, sinon « non renseigné » ; sinon
     * l’avancement est rapporté à l’avancement attendu à date (part écoulée
     * de la période prévue) et classé par les seuils de performance.
     */
    public function taskStatus(PapTask $task): string
    {
        $progress = $task->progress_percent;
        if ($progress !== null && (float) $progress >= 100) {
            return 'atteint';
        }
        if ($progress === null) {
            return $task->starts_on !== null && $task->starts_on->lt(today()) ? 'en_retard' : 'non_renseigne';
        }
        $expected = $this->expectedProgress($task->starts_on, $task->ends_on);
        $status = $this->calculator->performanceStatus($expected <= 0 ? 100.0 : round((float) $progress / $expected * 100, 2));

        return $status === 'atteint' ? 'en_bonne_voie' : $status;
    }

    public function weightedTotal(PapEnrichment $activity): float
    {
        return round(collect($this->tasks($activity))->sum('contribution'), 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indicators(PapEnrichment $activity): array
    {
        return Indicator::query()
            ->where('pap_enrichment_id', $activity->id)
            ->with(['targets.period', 'measurements' => fn ($query) => $query->whereIn('status', ['valide', 'consolide'])->whereNull('superseded_at')->with('period')])
            ->orderBy('code')
            ->get()
            ->map(fn (Indicator $indicator) => $this->indicatorRow($indicator))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function indicatorRow(Indicator $indicator): array
    {
        $latest = $indicator->measurements->sortByDesc(fn (IndicatorMeasurement $row) => $row->period?->opens_on?->timestamp ?? 0)->first();
        $target = $indicator->targets->sortByDesc(fn ($row) => $row->period?->closes_on?->timestamp ?? 0)->first();
        $rate = $latest?->attainment_rate ?? $this->calculator->attainment($indicator->direction, $target?->value, $latest?->value);

        return [
            'id' => $indicator->id,
            'code' => $indicator->code,
            'libelle' => $indicator->label,
            'type' => $indicator->type,
            'frequence' => $indicator->frequency,
            'sens' => $indicator->direction,
            'unite' => $indicator->unit,
            'reference' => $indicator->baseline_value,
            'reference_annee' => $indicator->baseline_on ? Carbon::parse($indicator->baseline_on)->year : null,
            'cible' => $target?->value,
            'cible_periode' => $target?->period?->label,
            'realise' => $latest?->value,
            'realise_periode' => $latest?->period?->label,
            'taux' => $rate,
            'statut' => $this->calculator->performanceStatus($rate),
        ];
    }

    private function expectedProgress(?Carbon $start, ?Carbon $end): float
    {
        if ($start === null || $end === null || $start->gt(today())) {
            return 0.0;
        }
        $total = max(1, $start->diffInDays($end));

        return min(100.0, round($start->diffInDays(min(today(), $end)) / $total * 100, 2));
    }

    /**
     * @param  Collection<int, PapEnrichment>  $activities
     * @return Collection<int, array<string, mixed>>
     */
    public function indicatorRowsFor(Collection $activities): Collection
    {
        return Indicator::query()
            ->whereIn('pap_enrichment_id', $activities->pluck('id'))
            ->with(['targets.period', 'measurements' => fn ($query) => $query->whereIn('status', ['valide', 'consolide'])->whereNull('superseded_at')->with('period')])
            ->get()
            ->map(fn (Indicator $indicator) => $this->indicatorRow($indicator) + ['pap_enrichment_id' => $indicator->pap_enrichment_id]);
    }
}
