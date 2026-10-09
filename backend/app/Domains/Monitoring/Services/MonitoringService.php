<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\IndicatorTarget;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeEvaluation;
use App\Domains\Monitoring\Models\SeProof;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Models\SeTransition;
use App\Domains\Monitoring\Notifications\MonitoringAlert;
use App\Domains\Monitoring\Support\SeReference;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Domains\Tasks\Services\TaskAudience;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Support\TransitionLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MonitoringService
{
    /**
     * @var list<string>
     */
    private const GLOBAL_ROLES = [
        'directeur_budget', 'controleur_financier', 'auditeur',
        'administrateur_fonctionnel', 'administrateur_habilitations',
        'secretaire_general', 'ordonnateur', 'responsable_se',
    ];

    public function __construct(
        private readonly IndicatorCalculationService $calculator,
        private readonly FinancialExecutionService $finances,
        private readonly PerformanceScoreService $scores,
        private readonly GanttService $gantt,
        private readonly ReferentialService $referentials,
    ) {}

    /**
     * @return Builder<PapEnrichment>
     */
    public function visible(User $user): Builder
    {
        $query = PapEnrichment::query()->with(['budgetLine.organizationUnit', 'tasks', 'responsible']);
        if (! $user->holds(...self::GLOBAL_ROLES)) {
            $query->whereHas('budgetLine', fn (Builder $line) => $line->where('organization_unit_id', $user->organization_unit_id));
        }

        return $query;
    }

    public function assertVisible(User $user, PapEnrichment $activity): void
    {
        abort_unless($this->visible($user)->whereKey($activity->id)->exists(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function activityCard(PapEnrichment $activity): array
    {
        $finance = $activity->budgetLine ? $this->finances->forLine($activity->budgetLine) : [];
        $physical = $this->physicalRate($activity);
        // Base de comparaison de la maquette S&E : « exécution financière (engagé) » = engagé / révisé.
        $financial = (float) ($finance['taux_engagement'] ?? 0);
        $gap = round($physical - $financial, 2);
        $level = $this->calculator->gapLevel($gap);
        $unit = $activity->budgetLine?->organizationUnit;
        $lateTasks = $activity->tasks->filter(fn (PapTask $task) => $task->ends_on !== null && $task->ends_on->lt(today()) && (float) $task->progress_percent < 100);
        $startDelay = $activity->date_debut && $activity->actual_start ? (int) $activity->date_debut->diffInDays($activity->actual_start, false) : null;
        $endDelay = $activity->date_fin && $activity->date_fin->lt(today()) && $physical < 100 ? (int) $activity->date_fin->diffInDays(today()) : 0;
        $taskDelay = (int) $lateTasks->map(fn (PapTask $task) => (int) $task->ends_on->diffInDays(today()))->max();

        return [
            'id' => $activity->id,
            'code' => $activity->code,
            'activite' => $activity->activite,
            'nature' => $activity->budgetLine?->nature?->value,
            'statut' => $this->activityStatus($activity, $physical, $lateTasks->isNotEmpty()),
            'pilier' => $activity->pilier,
            'axe' => $activity->axe,
            'produit' => $activity->produit,
            'sous_produit' => $activity->sous_produit,
            'ligne' => $activity->budgetLine?->code,
            'structure' => $unit?->sigle,
            'structure_libelle' => $unit?->name,
            'structure_id' => $unit?->id,
            'responsable' => $activity->responsible?->name,
            'responsable_id' => $activity->responsible_user_id,
            'unite_responsable' => $activity->unite_responsable,
            'date_debut' => $activity->date_debut?->toDateString(),
            'date_fin' => $activity->date_fin?->toDateString(),
            'debut_reel' => $activity->actual_start?->toDateString(),
            'fin_reelle' => $activity->actual_end?->toDateString(),
            'retard_demarrage' => $startDelay,
            'jours_restants' => $activity->date_fin && $activity->date_fin->gte(today()) ? (int) today()->diffInDays($activity->date_fin) : null,
            'retard_jours' => max($endDelay, $taskDelay),
            'physique' => $physical,
            'financier' => $financial,
            'paye_taux' => (float) ($finance['taux_execution'] ?? 0),
            'ecart' => $gap,
            'niveau_ecart' => $level['niveau'],
            'seuils_ecart' => ['surveiller' => $level['seuil_surveiller'], 'critique' => $level['seuil_critique']],
            'alerte' => $level['niveau'] === 'critique',
            'finances' => $finance,
            'taches' => $activity->tasks->map(fn (PapTask $task) => [
                'id' => $task->id,
                'libelle' => $task->label,
                'poids' => (int) $task->weight,
                'avancement' => (float) ($task->progress_percent ?? 0),
                'debut' => $task->starts_on?->toDateString(),
                'fin' => $task->ends_on?->toDateString(),
                'debut_reel' => $task->actual_start?->toDateString(),
                'fin_reelle' => $task->actual_end?->toDateString(),
                'depend_de' => $task->depends_on_id,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dossier(PapEnrichment $activity): array
    {
        $activity->load(['budgetLine.organizationUnit', 'tasks']);
        $card = $this->activityCard($activity);
        $indicators = Indicator::query()->where('pap_enrichment_id', $activity->id)->with(['targets', 'measurements'])->get();
        $risks = SeRisk::query()->where('pap_enrichment_id', $activity->id)->get();
        $variances = PerformanceVariance::query()->where('pap_enrichment_id', $activity->id)->get();
        $achievements = PhysicalAchievement::query()->where('pap_enrichment_id', $activity->id)->latest('id')->get();
        $actions = CorrectiveAction::query()->where('pap_enrichment_id', $activity->id)->get();
        $measurementIds = $indicators->flatMap->measurements->pluck('id');
        $achievementIds = $achievements->pluck('id');
        $proofs = SeProof::query()
            ->where(function (Builder $query) use ($measurementIds, $achievementIds) {
                $query->where(function (Builder $inner) use ($measurementIds) {
                    $inner->where('proofable_type', (new IndicatorMeasurement)->getMorphClass())
                        ->whereIn('proofable_id', $measurementIds);
                })->orWhere(function (Builder $inner) use ($achievementIds) {
                    $inner->where('proofable_type', (new PhysicalAchievement)->getMorphClass())
                        ->whereIn('proofable_id', $achievementIds);
                });
            })
            ->latest('id')
            ->get();
        $historyIds = $measurementIds->merge($achievementIds)->map(fn ($id) => (string) $id);
        $weight = max(1, (int) $activity->tasks->sum('weight'));

        return $card + [
            'planification' => [
                'pilier' => $activity->pilier,
                'axe' => $activity->axe,
                'produit' => $activity->produit,
                'sous_produit' => $activity->sous_produit,
                'objectif' => $activity->objectif_specifique ?: $activity->objectif_general,
                'resultats' => $activity->resultats_attendus,
                'responsable' => $activity->unite_responsable,
                'periode' => $activity->periode,
                'debut' => $activity->date_debut?->toDateString(),
                'fin' => $activity->date_fin?->toDateString(),
                'beneficiaires' => $activity->beneficiaires,
            ],
            'contributions' => $activity->tasks->map(fn (PapTask $task) => round((float) ($task->progress_percent ?? 0) * (int) $task->weight / $weight, 2)),
            'indicateurs' => $indicators,
            'realisations' => $achievements,
            'ecarts' => $variances,
            'risques' => $risks->map(fn (SeRisk $risk) => $risk->toArray() + ['criticite' => $risk->criticite()]),
            'mesures' => $actions,
            'livrables' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $activity->livrables) ?: []))),
            'jalons' => $activity->tasks->map(fn (PapTask $task) => [
                'id' => $task->id,
                'libelle' => $task->label,
                'prevu' => $task->ends_on?->toDateString(),
                'reel' => $task->actual_end?->toDateString(),
                'statut' => $task->actual_end ? 'franchi' : ($task->ends_on && $task->ends_on->lt(today()) ? 'en retard' : 'ouvert'),
            ]),
            'preuves' => $proofs,
            'historique' => AuditEvent::query()
                ->where('action', 'like', 'se.%')
                ->whereIn('object_id', $historyIds)
                ->latest('id')
                ->limit(30)
                ->get(['id', 'action', 'object_type', 'object_id', 'motif', 'created_at']),
            'chaine' => $this->chain($activity),
            'score' => $this->scores->score($card, $indicators, $risks, $variances->where('status', 'critique')->count()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(User $user, PapTask $task, array $data): PapTask
    {
        $activity = $task->enrichment()->firstOrFail();
        $this->assertVisible($user, $activity);
        $dependsOn = array_key_exists('depends_on_id', $data) && $data['depends_on_id'] !== null
            ? (int) $data['depends_on_id']
            : null;
        $this->gantt->assertSchedule($task, $dependsOn);
        // Une fois le planning initial validé (référence posée), les dates prévues et
        // la dépendance ne changent que par une révision validée ou par la hiérarchie.
        $replanifie = (array_key_exists('starts_on', $data) && $data['starts_on'] !== $task->starts_on?->toDateString())
            || (array_key_exists('ends_on', $data) && $data['ends_on'] !== $task->ends_on?->toDateString())
            || (array_key_exists('depends_on_id', $data) && $dependsOn !== $task->depends_on_id);
        if ($replanifie && ($task->baseline_starts_on !== null || $task->baseline_ends_on !== null) && ! $user->holds(...ActivityGanttService::PLANNING_VALIDATORS)) {
            throw ValidationException::withMessages(['planning' => 'Le planning initial est validé : proposez un nouveau planning, il sera soumis à la hiérarchie.']);
        }
        $previousEnds = $task->ends_on?->copy();
        $previousActual = $task->actual_end?->copy();
        if (! empty($data['starts_on']) && ! empty($data['ends_on']) && $data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'La fin prévue ne peut pas précéder le début.']);
        }
        if (! empty($data['actual_start']) && ! empty($data['actual_end']) && $data['actual_end'] < $data['actual_start']) {
            throw ValidationException::withMessages(['actual_end' => 'La fin réelle ne peut pas précéder le début réel.']);
        }
        $task->fill([
            'starts_on' => array_key_exists('starts_on', $data) ? $data['starts_on'] : $task->starts_on,
            'ends_on' => array_key_exists('ends_on', $data) ? $data['ends_on'] : $task->ends_on,
            'actual_start' => array_key_exists('actual_start', $data) ? $data['actual_start'] : $task->actual_start,
            'actual_end' => array_key_exists('actual_end', $data) ? $data['actual_end'] : $task->actual_end,
            'depends_on_id' => array_key_exists('depends_on_id', $data) ? $dependsOn : $task->depends_on_id,
        ])->save();
        $this->shiftLateDependents($task, $previousEnds, $previousActual);

        return $task->fresh('predecessor');
    }

    private function shiftLateDependents(PapTask $task, mixed $previousEnds, mixed $previousActual): void
    {
        $delta = $this->latenessDays($task->ends_on, $task->actual_end) - $this->latenessDays($previousEnds, $previousActual);
        if ($delta === 0) {
            return;
        }
        $this->pushDependents($task->id, $delta, [$task->id]);
    }

    private function latenessDays(mixed $planned, mixed $actual): int
    {
        if ($planned === null || $actual === null || $actual->lte($planned)) {
            return 0;
        }

        return (int) $planned->diffInDays($actual);
    }

    /**
     * @param  list<int>  $seen
     */
    private function pushDependents(int $taskId, int $delta, array $seen): void
    {
        PapTask::query()->where('depends_on_id', $taskId)->get()->each(function (PapTask $child) use ($delta, $seen): void {
            if (in_array($child->id, $seen, true)) {
                return;
            }
            $child->forceFill([
                'starts_on' => $child->starts_on?->copy()->addDays($delta),
                'ends_on' => $child->ends_on?->copy()->addDays($delta),
            ])->save();
            $this->pushDependents($child->id, $delta, [...$seen, $child->id]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chain(PapEnrichment $activity): array
    {
        $lineId = $activity->budget_line_id;
        if ($lineId === null) {
            return [];
        }
        $rows = [];
        foreach (ExpressionBesoin::query()->where('budget_line_id', $lineId)->get() as $row) {
            $rows[] = $this->chainRow('EB', $row->reference, $row->status, (int) $row->montant);
        }
        $engagements = Engagement::query()->where('budget_line_id', $lineId)->get();
        foreach ($engagements as $row) {
            $rows[] = $this->chainRow('ENG', $row->reference, $row->status, (int) $row->montant);
        }
        $liquidations = Liquidation::query()->whereIn('engagement_id', $engagements->pluck('id'))->get();
        foreach ($liquidations as $row) {
            $rows[] = $this->chainRow('LIQ', $row->reference, $row->status, (int) $row->montant_net);
        }
        $orders = Ordonnancement::query()->whereIn('liquidation_id', $liquidations->pluck('id'))->get();
        foreach ($orders as $row) {
            $rows[] = $this->chainRow('ORD', $row->reference, $row->status, (int) $row->montant);
        }
        foreach (Paiement::query()->whereIn('ordonnancement_id', $orders->pluck('id'))->get() as $row) {
            $rows[] = $this->chainRow('PAY', $row->reference, $row->status, (int) $row->montant_paye);
        }

        return $rows;
    }

    /**
     * @return array{maillon: string, reference: string, statut: string, montant: int}
     */
    private function chainRow(string $link, string $reference, mixed $status, int $amount): array
    {
        return [
            'maillon' => $link,
            'reference' => $reference,
            'statut' => $status instanceof \BackedEnum ? $status->value : (string) $status,
            'montant' => $amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $activities = $this->visible($user)->get();
        $this->finances->prime($activities->pluck('budget_line_id'));
        $cards = $activities->map(fn (PapEnrichment $row) => $this->activityCard($row));
        $physical = $cards->isEmpty() ? 0 : round($cards->avg('physique'), 2);
        $financial = $cards->isEmpty() ? 0 : round($cards->avg('financier'), 2);

        return [
            'physique' => $physical,
            'financier' => $financial,
            'ecart' => round($physical - $financial, 2),
            'activites' => $cards->count(),
            'en_retard' => $cards->filter(fn (array $card) => $card['date_fin'] !== null && $card['date_fin'] < now()->toDateString() && $card['physique'] < 100)->count(),
            'critiques' => $cards->where('alerte', true)->count(),
            'montant_paye' => (int) $cards->sum('finances.paye'),
            'risques_critiques' => SeRisk::query()->whereIn('pap_enrichment_id', $activities->pluck('id'))->where('status', '!=', 'clos')->get()
                ->filter(fn (SeRisk $risk) => $risk->criticite() === 'critique')->count(),
            'recommandations_echues' => SeRecommendation::query()
                ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $activities->pluck('id')))
                ->get()->filter(fn (SeRecommendation $row) => $row->late())->count(),
            'mesures_en_retard' => CorrectiveAction::query()
                ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $activities->pluck('id')))
                ->get()->filter(fn (CorrectiveAction $row) => $row->late())->count(),
            'activites_detail' => $cards->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function measure(User $user, array $data): IndicatorMeasurement
    {
        $this->rejectFinancialInput($data);
        $indicator = Indicator::query()->findOrFail($data['indicator_id']);
        if ($indicator->pap_enrichment_id) {
            $this->assertVisible($user, PapEnrichment::query()->findOrFail($indicator->pap_enrichment_id));
        }
        $entry = app(MeasurementEntryService::class);
        $period = MonitoringPeriod::query()->findOrFail($data['monitoring_period_id']);
        [$value] = $entry->compute(
            $indicator,
            isset($data['numerator']) ? (float) $data['numerator'] : null,
            isset($data['denominator']) ? (float) $data['denominator'] : null,
            isset($data['value']) ? (float) $data['value'] : null,
        );
        if ($value === null) {
            throw ValidationException::withMessages(['value' => 'La valeur de la période est obligatoire.']);
        }
        $data['value'] = $value;
        $rate = $this->calculator->attainment($indicator->direction, $entry->target($indicator, $period), $value);
        $active = IndicatorMeasurement::query()
            ->where('indicator_id', $indicator->id)
            ->where('monitoring_period_id', $data['monitoring_period_id'])
            ->whereNull('superseded_at')
            ->where('status', '!=', 'rejete')
            ->first();
        if ($active !== null) {
            throw ValidationException::withMessages([
                'value' => 'Une valeur existe déjà pour cet indicateur et cette période (statut '.$active->status.') : '.($active->status === 'valide' ? 'rectifiez-la.' : 'complétez ou corrigez celle-ci.'),
            ]);
        }

        $row = IndicatorMeasurement::query()->create([
            'indicator_id' => $indicator->id,
            'monitoring_period_id' => $data['monitoring_period_id'],
            'value' => $data['value'],
            'status' => 'brouillon',
            'version' => 1,
            'formula_version' => $indicator->formula_version,
            'attainment_rate' => $rate,
            'numerator' => $data['numerator'] ?? null,
            'denominator' => $data['denominator'] ?? null,
            'justification' => $data['justification'] ?? null,
            'comment' => $data['comment'] ?? null,
            'source' => $data['source'] ?? null,
            'author_id' => $user->id,
        ]);
        FinancialAudit::record($user, 'se.mesure.creer', 'indicator_measurement', (string) $row->id, null, ['valeur' => $row->value]);

        return $row;
    }

    /**
     * Transition paramétrée d’une mesure (soumettre, valider, rejeter,
     * corriger). La mesure est verrouillée ; l’auteur ne valide pas sa propre
     * saisie (description S&E §81) ; un rejet ou un retour exige un motif.
     */
    public function transitionMeasurement(User $user, IndicatorMeasurement $row, string $action, ?string $motif = null): IndicatorMeasurement
    {
        return TransitionLock::run($row, function (IndicatorMeasurement $row) use ($user, $action, $motif) {
            $indicator = $row->indicator;
            if ($indicator?->pap_enrichment_id) {
                $activity = PapEnrichment::query()->findOrFail($indicator->pap_enrichment_id);
                $this->assertCanAct($user, $activity, $row, $action);
            } elseif (($refusal = $this->refusal($user, new PapEnrichment, $row, $action)) !== null) {
                throw ValidationException::withMessages(['action' => $refusal]);
            }
            $this->assertTransitionAllowed($user, $row, $action, $motif);
            if ($action === 'valider' && ! $this->hasProof($row)) {
                throw ValidationException::withMessages(['preuve' => 'Une preuve est obligatoire avant validation.']);
            }
            $to = $this->nextStatus($row->status, $action);
            $target = IndicatorTarget::query()
                ->where('indicator_id', $row->indicator_id)
                ->where('monitoring_period_id', $row->monitoring_period_id)
                ->value('value');
            $from = $row->status;
            $row->forceFill([
                ...$this->levelStamps($user, $row, $to),
                'status' => $to,
                'submitted_at' => $action === 'soumettre' ? now() : $row->submitted_at,
                'rejection_motif' => in_array($action, ['rejeter', 'corriger'], true) ? $motif : $row->rejection_motif,
                'attainment_rate' => $to === 'valide' ? $this->calculator->attainment($indicator->direction, $target !== null ? (float) $target : null, (float) $row->value) : $row->attainment_rate,
                'formula_version' => $to === 'valide' ? $indicator->formula_version : $row->formula_version,
            ])->save();
            if ($to === 'valide' && $row->supersedes_id !== null) {
                IndicatorMeasurement::query()->whereKey($row->supersedes_id)->whereNull('superseded_at')->first()?->forceFill(['superseded_at' => now()])->save();
            }
            FinancialAudit::record($user, 'se.mesure.'.$action, 'indicator_measurement', (string) $row->id, ['statut' => $from], ['statut' => $to], $motif);
            $this->notifyTransition($user, $row->author_id, 'Mesure « '.$indicator?->label.' » '.$to, '/suivi/saisie', $action);

            return $row->fresh();
        });
    }

    /**
     * Rectification d’une valeur validée : une nouvelle version est créée et
     * suit le circuit complet. La version validée reste la référence tant que
     * la rectification n’est pas elle-même validée.
     */
    public function correctMeasurement(User $user, IndicatorMeasurement $row, float $value, string $motif): IndicatorMeasurement
    {
        return TransitionLock::run($row, fn (IndicatorMeasurement $row) => $this->performCorrectMeasurement($user, $row, $value, $motif));
    }

    private function performCorrectMeasurement(User $user, IndicatorMeasurement $row, float $value, string $motif): IndicatorMeasurement
    {
        if (! in_array($row->status, ['valide', 'consolide'], true) || $row->superseded_at !== null) {
            throw ValidationException::withMessages(['statut' => 'Seule la version validée en vigueur se rectifie par une nouvelle version.']);
        }
        if (IndicatorMeasurement::query()->where('supersedes_id', $row->id)->whereNotIn('status', ['rejete'])->exists()) {
            throw ValidationException::withMessages(['statut' => 'Une rectification de cette valeur est déjà en cours.']);
        }
        $copy = $row->replicate(['validated_at', 'validator_id', 'submitted_at', 'superseded_at']);
        $copy->forceFill([
            'value' => $value,
            'status' => 'brouillon',
            'version' => $row->version + 1,
            'supersedes_id' => $row->id,
            'comment' => $motif,
            'author_id' => $user->id,
            'attainment_rate' => $this->calculator->attainment($row->indicator->direction, IndicatorTarget::query()->where('indicator_id', $row->indicator_id)->where('monitoring_period_id', $row->monitoring_period_id)->value('value'), $value),
        ])->save();
        FinancialAudit::record($user, 'se.mesure.rectifier', 'indicator_measurement', (string) $copy->id, ['valeur' => $row->value], ['valeur' => $value], $motif);

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function achieve(User $user, array $data): PhysicalAchievement
    {
        $this->rejectFinancialInput($data);
        $activity = PapEnrichment::query()->with('tasks')->findOrFail($data['pap_enrichment_id']);
        $this->assertVisible($user, $activity);
        $percent = $this->calculator->physical($data['method'], (float) $data['quantity'], (float) $data['planned'], (bool) ($data['atteint'] ?? false));
        if ($percent > 100 && blank($data['exception_motif'] ?? null)) {
            throw ValidationException::withMessages(['exception_motif' => 'Un avancement supérieur à 100 % exige une justification.']);
        }
        $taskId = $data['pap_task_id'] ?? null;
        if ($taskId !== null && ! $activity->tasks->contains('id', (int) $taskId)) {
            throw ValidationException::withMessages(['pap_task_id' => 'Cette tâche n’appartient pas à l’activité.']);
        }
        $pending = PhysicalAchievement::query()
            ->where('pap_enrichment_id', $activity->id)
            ->where('monitoring_period_id', $data['monitoring_period_id'])
            ->when($taskId !== null, fn ($query) => $query->where('pap_task_id', $taskId), fn ($query) => $query->whereNull('pap_task_id'))
            ->whereIn('status', ['brouillon', 'soumis', 'a_corriger'])
            ->exists();
        if ($pending) {
            throw ValidationException::withMessages(['quantity' => 'Une réalisation est déjà en cours de saisie ou de validation pour cette période : complétez-la.']);
        }
        $row = PhysicalAchievement::query()->create([
            'pap_enrichment_id' => $activity->id,
            'pap_task_id' => $data['pap_task_id'] ?? null,
            'monitoring_period_id' => $data['monitoring_period_id'],
            'method' => $data['method'],
            'quantity' => $data['quantity'],
            'planned' => $data['planned'],
            'progress_percent' => $percent,
            'status' => 'brouillon',
            'comment' => $data['comment'] ?? null,
            'difficulties' => $data['difficulties'] ?? null,
            'exception_motif' => $data['exception_motif'] ?? null,
            'author_id' => $user->id,
        ]);
        FinancialAudit::record($user, 'se.realisation.creer', 'physical_achievement', (string) $row->id, null, ['avancement' => $percent]);

        return $row;
    }

    /**
     * Circuit d’une réalisation physique (description S&E §56) : seule une
     * réalisation validée alimente l’avancement de la tâche et le taux
     * physique de l’activité.
     */
    public function transitionAchievement(User $user, PhysicalAchievement $row, string $action, ?string $motif = null): PhysicalAchievement
    {
        return TransitionLock::run($row, function (PhysicalAchievement $row) use ($user, $action, $motif) {
            $activity = PapEnrichment::query()->findOrFail($row->pap_enrichment_id);
            $this->assertCanAct($user, $activity, $row, $action);
            $this->assertTransitionAllowed($user, $row, $action, $motif);
            if ($action === 'valider' && ! $this->hasProof($row)) {
                throw ValidationException::withMessages(['preuve' => 'Une preuve de réalisation est obligatoire avant validation.']);
            }
            $from = $row->status;
            $to = $this->nextStatus($from, $action);
            $row->forceFill([
                ...$this->levelStamps($user, $row, $to),
                'status' => $to,
                'submitted_at' => $action === 'soumettre' ? now() : $row->submitted_at,
                'rejection_motif' => in_array($action, ['rejeter', 'corriger'], true) ? $motif : $row->rejection_motif,
            ])->save();

            if ($to === 'valide') {
                PhysicalAchievement::query()
                    ->where('pap_enrichment_id', $row->pap_enrichment_id)
                    ->where('monitoring_period_id', $row->monitoring_period_id)
                    ->when($row->pap_task_id !== null, fn ($query) => $query->where('pap_task_id', $row->pap_task_id), fn ($query) => $query->whereNull('pap_task_id'))
                    ->whereKeyNot($row->id)
                    ->whereIn('status', ['valide', 'consolide'])
                    ->whereNull('superseded_at')
                    ->get()
                    ->each(fn (PhysicalAchievement $previous) => $previous->forceFill(['superseded_at' => now()])->save());
                if ($row->pap_task_id !== null) {
                    PapTask::query()->whereKey($row->pap_task_id)->update(['progress_percent' => $row->progress_percent]);
                }
            }
            FinancialAudit::record($user, 'se.realisation.'.$action, 'physical_achievement', (string) $row->id, ['statut' => $from], ['statut' => $to, 'avancement' => $row->progress_percent], $motif);
            $this->notifyTransition($user, $row->author_id, 'Réalisation « '.$activity->activite.' » '.$to, '/suivi/saisie', $action);

            return $row->fresh();
        });
    }

    /**
     * Contrôles communs aux transitions S&E : motif pour un rejet ou un retour,
     * séparation saisie / validation.
     */
    private function assertTransitionAllowed(User $user, IndicatorMeasurement|PhysicalAchievement $row, string $action, ?string $motif): void
    {
        if (in_array($action, ['rejeter', 'corriger'], true) && blank($motif)) {
            throw ValidationException::withMessages(['motif' => 'Un motif est obligatoire pour un rejet ou un retour en correction.']);
        }
        if (in_array($action, ['soumettre'], true) && $row->author_id !== null && $row->author_id !== $user->id) {
            throw ValidationException::withMessages(['action' => 'Seul l’auteur de la saisie la soumet.']);
        }
        if (in_array($action, ['valider', 'rejeter', 'corriger'], true) && $row->author_id === $user->id) {
            throw ValidationException::withMessages(['action' => 'Séparation des fonctions : vous ne pouvez pas contrôler votre propre saisie.']);
        }
    }

    private function hasProof(IndicatorMeasurement|PhysicalAchievement $row): bool
    {
        return SeProof::query()->where('proofable_type', $row->getMorphClass())->where('proofable_id', $row->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function variance(User $user, array $data): PerformanceVariance
    {
        $activity = PapEnrichment::query()->findOrFail($data['pap_enrichment_id']);
        $this->assertVisible($user, $activity);
        $this->referentials->assertActive('cause', $data['cause_category'] ?? null);
        $card = $this->activityCard($activity->load('budgetLine.organizationUnit', 'tasks'));
        $row = PerformanceVariance::query()->create([
            'reference' => SeReference::next('EC'),
            'pap_enrichment_id' => $activity->id,
            'kind' => $data['kind'],
            'physical_rate' => $card['physique'],
            'financial_rate' => $card['financier'],
            'gap' => $card['ecart'],
            'cause_category' => $data['cause_category'] ?? null,
            'cause' => $data['cause'] ?? null,
            'consequence' => $data['consequence'] ?? null,
            'comment' => $data['comment'] ?? null,
            'responsible_role' => $data['responsible_role'],
            'due_on' => $data['due_on'] ?? null,
            'status' => $card['niveau_ecart'] === 'critique' ? 'critique' : 'ouvert',
        ]);
        if ($row->status === 'critique') {
            $this->notify($user, 'Écart critique sur '.$activity->activite, '/suivi/ecarts');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function corrective(User $user, array $data): CorrectiveAction
    {
        if (blank($data['responsible_role'] ?? null)) {
            throw ValidationException::withMessages(['responsible_role' => 'Une mesure corrective exige un responsable.']);
        }
        $activityId = $data['pap_enrichment_id']
            ?? (isset($data['performance_variance_id']) ? PerformanceVariance::query()->whereKey($data['performance_variance_id'])->value('pap_enrichment_id') : null);
        if ($activityId !== null) {
            $this->assertVisible($user, PapEnrichment::query()->findOrFail($activityId));
        }
        $action = CorrectiveAction::query()->create([
            ...$data,
            'pap_enrichment_id' => $activityId,
            'created_by' => $user->id,
        ]);
        FinancialAudit::record($user, 'se.mesure_corrective.creer', 'corrective_action', (string) $action->id, null, ['responsable' => $action->responsible_role, 'echeance' => $action->due_on?->toDateString()]);

        return $action;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function risk(User $user, array $data): SeRisk
    {
        $activity = PapEnrichment::query()->findOrFail($data['pap_enrichment_id']);
        $this->assertVisible($user, $activity);
        $risk = SeRisk::query()->create([
            ...$data,
            'reference' => 'RSK-'.now()->year.'-'.str_pad((string) (SeRisk::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        ]);
        if ($risk->criticite() === 'critique') {
            $this->notify($user, 'Risque critique '.$risk->reference, '/suivi/activites/'.$activity->id);
        }

        return $risk;
    }

    public function recommendation(User $user, array $data): SeRecommendation
    {
        $row = SeRecommendation::query()->create([
            ...$data,
            'reference' => 'REC-'.now()->year.'-'.str_pad((string) (SeRecommendation::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        ]);
        if ($row->late()) {
            $this->notify($user, 'Recommandation échue '.$row->reference, '/suivi/synthese');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluation(array $data): SeEvaluation
    {
        foreach ($data['criteria'] ?? [] as $code) {
            $this->referentials->assertActive('critere', (string) $code);
        }

        return SeEvaluation::query()->create([
            ...$data,
            'reference' => 'EVL-'.now()->year.'-'.str_pad((string) (SeEvaluation::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        ]);
    }

    public function proof(User $user, string $type, int $id, string $category, mixed $file): SeProof
    {
        $contents = file_get_contents($file->getRealPath());
        $hash = hash('sha256', (string) $contents);
        $path = 'se-preuves/'.$type.'/'.$id.'/'.$hash;
        Storage::disk('local')->put($path, (string) $contents);

        return SeProof::query()->create([
            'proofable_type' => $type,
            'proofable_id' => $id,
            'category' => $category,
            'path' => $path,
            'sha256' => $hash,
            'confidentiality' => 'interne',
            'version' => 1,
            'author_id' => $user->id,
            'organization_unit_id' => $user->organization_unit_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function indicator(User $user, array $data): Indicator
    {
        if (! empty($data['pap_enrichment_id'])) {
            $this->assertVisible($user, PapEnrichment::query()->findOrFail($data['pap_enrichment_id']));
        }

        return Indicator::query()->create($data);
    }

    public function target(User $user, Indicator $indicator, int $periodId, float $value): IndicatorTarget
    {
        if ($indicator->pap_enrichment_id) {
            $this->assertVisible($user, PapEnrichment::query()->findOrFail($indicator->pap_enrichment_id));
        }

        // Règle en vigueur : tout acteur qui voit l’activité fixe ses cibles. Chaque
        // modification est tracée (ancienne et nouvelle valeur).
        return DB::transaction(function () use ($user, $indicator, $periodId, $value): IndicatorTarget {
            $avant = IndicatorTarget::query()->where('indicator_id', $indicator->id)->where('monitoring_period_id', $periodId)->value('value');
            $cible = IndicatorTarget::query()->updateOrCreate(
                ['indicator_id' => $indicator->id, 'monitoring_period_id' => $periodId],
                ['value' => $value],
            );
            FinancialAudit::record($user, 'suivi.cible', 'indicator', (string) $indicator->id, ['periode' => $periodId, 'cible' => $avant], ['periode' => $periodId, 'cible' => $value]);

            return $cible;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function consolidate(User $user, string $level): array
    {
        abort_unless(in_array($level, ['pilier', 'axe', 'produit', 'sous_produit', 'activite'], true), 422);
        $activities = $this->visible($user)->get();
        $this->finances->prime($activities->pluck('budget_line_id'));
        $cards = $activities->map(fn (PapEnrichment $row) => $this->activityCard($row));

        return $cards->groupBy(fn (array $card) => (string) ($card[$level] ?: 'Non renseigné'))
            ->map(fn ($group, $key) => [
                'cle' => $key,
                'physique' => round((float) $group->avg('physique'), 2),
                'financier' => round((float) $group->avg('financier'), 2),
                'paye' => (int) $group->sum('finances.paye'),
                'activites' => $group->count(),
            ])->values()->all();
    }

    public function physicalRate(PapEnrichment $activity): float
    {
        $tasks = $activity->relationLoaded('tasks') ? $activity->tasks : $activity->tasks()->get();
        $weighted = $tasks->filter(fn (PapTask $task) => $task->progress_percent !== null);
        if ($weighted->isNotEmpty()) {
            return $this->calculator->weighted($weighted->map(fn (PapTask $task) => [
                'progress' => (float) $task->progress_percent,
                'weight' => max(1, (int) $task->weight),
            ])->all());
        }
        $latest = PhysicalAchievement::query()
            ->where('pap_enrichment_id', $activity->id)
            ->whereNull('pap_task_id')
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNull('superseded_at')
            ->latest('validated_at')
            ->value('progress_percent');

        return round((float) $latest, 2);
    }

    /**
     * Rôles admis au premier niveau quand aucun responsable d’activité n’est
     * désigné ; au niveau hiérarchique ; à la consolidation.
     *
     * @var array<string, list<string>>
     */
    public const LEVEL_ROLES = [
        'responsable' => ['chef_service', 'directeur', 'directeur_budget', 'controleur_financier'],
        'hierarchique' => ['directeur', 'directeur_budget', 'controleur_financier', 'commissaire', 'secretaire_general'],
        'consolidation' => ['responsable_se', 'directeur_budget', 'administrateur_fonctionnel'],
    ];

    /**
     * Étape du circuit à laquelle se rattache une action (maquette S&E,
     * écran 4) : validation responsable, validation hiérarchique,
     * consolidation.
     */
    public function decisionLevel(string $status, string $action): ?string
    {
        return match (true) {
            $status === 'soumis' && in_array($action, ['valider', 'rejeter', 'corriger'], true) => 'responsable',
            $status === 'valide_responsable' && in_array($action, ['valider', 'rejeter', 'corriger'], true) => 'hierarchique',
            $status === 'valide' && $action === 'consolider' => 'consolidation',
            default => null,
        };
    }

    /**
     * Motif du refus, ou null si l’utilisateur peut effectuer l’action. Sert
     * au contrôle serveur et au calcul des boutons affichés.
     */
    public function refusal(User $user, PapEnrichment $activity, IndicatorMeasurement|PhysicalAchievement $row, string $action): ?string
    {
        if ($action === 'soumettre') {
            return $row->author_id === null || $row->author_id === $user->id ? null : 'Seul l’auteur de la saisie la soumet.';
        }
        $level = $this->decisionLevel((string) $row->status, $action);
        if ($level === null) {
            return null;
        }
        if ($row->author_id === $user->id) {
            return 'Séparation des fonctions : vous ne pouvez pas contrôler votre propre saisie.';
        }
        if ($level === 'responsable') {
            if ($activity->responsible_user_id !== null) {
                return $activity->responsible_user_id === $user->id ? null : 'La validation de premier niveau revient au responsable de l’activité.';
            }

            return $user->holds(...self::LEVEL_ROLES['responsable']) ? null : 'La validation de premier niveau revient au responsable de l’activité.';
        }
        if ($level === 'hierarchique') {
            if ($row->responsible_validator_id === $user->id) {
                return 'Séparation des fonctions : la validation hiérarchique est faite par un autre acteur que la validation responsable.';
            }

            return $user->holds(...self::LEVEL_ROLES['hierarchique']) ? null : 'La validation hiérarchique revient à la hiérarchie de l’activité.';
        }

        return $user->holds(...self::LEVEL_ROLES['consolidation']) ? null : 'La consolidation revient au responsable S&E.';
    }

    private function assertCanAct(User $user, PapEnrichment $activity, IndicatorMeasurement|PhysicalAchievement $row, string $action): void
    {
        $this->assertVisible($user, $activity);
        $refusal = $this->refusal($user, $activity, $row, $action);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['action' => $refusal]);
        }
    }

    /**
     * Traçabilité de chaque niveau de validation.
     *
     * @return array<string, mixed>
     */
    private function levelStamps(User $user, IndicatorMeasurement|PhysicalAchievement $row, string $to): array
    {
        return match ($to) {
            'valide_responsable' => ['responsible_validator_id' => $user->id, 'responsible_validated_at' => now()],
            'valide' => ['validator_id' => $user->id, 'validated_at' => now()],
            'consolide' => ['consolidated_by' => $user->id, 'consolidated_at' => now()],
            default => [],
        };
    }

    private function nextStatus(string $from, string $action): string
    {
        $to = SeTransition::query()->where('from_status', $from)->where('action', $action)->value('to_status');
        if (! is_string($to)) {
            throw ValidationException::withMessages(['action' => 'Cette transition n’est pas paramétrée.']);
        }

        return $to;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectFinancialInput(array $data): void
    {
        foreach (['montant_paye', 'engage', 'liquide', 'ordonnance', 'budget_revise'] as $key) {
            if (array_key_exists($key, $data)) {
                throw ValidationException::withMessages([$key => 'Les montants financiers proviennent de la chaîne de dépense et ne se saisissent pas ici.']);
            }
        }
    }

    /**
     * Statut d’exécution d’une activité (description S&E §9) : un statut posé
     * explicitement (suspendue, bloquée, annulée, clôturée) prime ; sinon il
     * se déduit de l’avancement validé et du calendrier.
     */
    public function activityStatus(PapEnrichment $activity, float $physical, bool $hasLateTask): string
    {
        if (filled($activity->se_status)) {
            return (string) $activity->se_status;
        }

        return match (true) {
            $physical >= 100 => 'realisee',
            ($activity->date_fin !== null && $activity->date_fin->lt(today())) || $hasLateTask => 'en_retard',
            $physical > 0 || $activity->actual_start !== null => 'en_cours',
            $activity->date_debut !== null && $activity->date_debut->gt(today()) => 'planifiee',
            default => 'non_demarree',
        };
    }

    /**
     * Affecte le directeur de la structure propriétaire de la ligne lorsqu’aucune
     * personne n’a encore été désignée. Ne remplace pas un responsable déjà saisi.
     */
    public function assignDefaultResponsibles(): void
    {
        $missing = PapEnrichment::query()
            ->whereNull('responsible_user_id')
            ->whereHas('budgetLine')
            ->with('budgetLine:id,organization_unit_id')
            ->get();
        if ($missing->isEmpty()) {
            return;
        }

        $directors = User::query()
            ->where('role', 'directeur')
            ->whereIn('organization_unit_id', $missing->pluck('budgetLine.organization_unit_id')->filter()->unique())
            ->orderBy('id')
            ->get()
            ->unique('organization_unit_id')
            ->keyBy('organization_unit_id');

        foreach ($missing as $activity) {
            $director = $directors->get($activity->budgetLine?->organization_unit_id);
            if ($director !== null) {
                $activity->update(['responsible_user_id' => $director->id]);
            }
        }
    }

    /**
     * Une soumission crée la tâche du valideur (projection des tâches) ;
     * une décision prévient l’auteur.
     */
    private function notifyTransition(User $actor, ?int $authorId, string $message, string $lien, string $action): void
    {
        if ($action === 'soumettre') {
            return;
        }
        $author = $authorId !== null ? User::query()->find($authorId) : null;
        $author?->notify(new MonitoringAlert($message, $lien));
    }

    private function notify(User $actor, string $message, string $lien): void
    {
        $targets = app(TaskAudience::class)->forRole('directeur', $actor->organization_unit_id);
        if ($targets->isEmpty()) {
            $targets = collect([$actor]);
        }
        DB::transaction(function () use ($targets, $message, $lien) {
            foreach ($targets as $target) {
                $target->notify(new MonitoringAlert($message, $lien));
            }
        });
    }
}
