<?php

namespace App\Domains\Monitoring\Http\Controllers;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Monitoring\Exports\MonitoringReportExport;
use App\Domains\Monitoring\Http\Requests\StoreCorrectiveActionRequest;
use App\Domains\Monitoring\Http\Requests\StoreEvaluationRequest;
use App\Domains\Monitoring\Http\Requests\StoreIndicatorMeasurementRequest;
use App\Domains\Monitoring\Http\Requests\StoreRiskRequest;
use App\Domains\Monitoring\Http\Requests\SubmitPhysicalAchievementRequest;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeEvaluation;
use App\Domains\Monitoring\Models\SeProof;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeReferential;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Services\CollectionCampaignService;
use App\Domains\Monitoring\Services\FollowUpService;
use App\Domains\Monitoring\Services\GanttService;
use App\Domains\Monitoring\Services\IndicatorAggregationService;
use App\Domains\Monitoring\Services\IndicatorCalculationService;
use App\Domains\Monitoring\Services\MonitoringService;
use App\Domains\Monitoring\Services\PortfolioGanttService;
use App\Domains\Monitoring\Services\ReferentialService;
use App\Domains\Monitoring\Services\ReportingService;
use App\Domains\Monitoring\Services\VarianceDossierService;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Http\Controllers\Controller;
use App\Shared\Documents\OfficialDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class MonitoringController extends Controller
{
    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly CollectionCampaignService $campaigns,
    ) {}

    public function dashboard(): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);

        return response()->json(['data' => $this->monitoring->dashboard(request()->user())]);
    }

    public function activities(): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);
        $rows = $this->monitoring->visible(request()->user())->get()
            ->map(fn (PapEnrichment $row) => $this->monitoring->activityCard($row));

        return response()->json(['data' => $rows]);
    }

    public function activity(PapEnrichment $papEnrichment): JsonResponse
    {
        $this->monitoring->assertVisible(request()->user(), $papEnrichment);

        return response()->json(['data' => $this->monitoring->dossier($papEnrichment)]);
    }

    public function storeIndicator(Request $request): JsonResponse
    {
        $this->authorize('create', Indicator::class);
        $data = $request->validate([
            'pap_enrichment_id' => ['nullable', 'integer', 'exists:pap_enrichments,id'],
            'code' => ['required', 'string', 'unique:indicators,code'],
            'label' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string'],
            'gar_level' => ['nullable', 'string'],
            'unit' => ['nullable', 'string'],
            'direction' => ['required', 'in:croissant,decroissant,binaire,qualitatif'],
            'aggregation' => ['nullable', 'in:sum,average,weighted_average,min,max,last_value,custom_formula,non_aggregatable'],
            'weight' => ['nullable', 'integer', 'min:1'],
            'formula' => ['nullable', 'in:moyenne_taux'],
            'baseline_value' => ['nullable', 'numeric'],
            'baseline_on' => ['nullable', 'date'],
            'source' => ['nullable', 'string'],
            'responsible_role' => ['nullable', 'string'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return response()->json(['data' => $this->monitoring->indicator($request->user(), $data)], 201);
    }

    public function indicators(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);

        return response()->json(['data' => Indicator::query()
            ->with(['targets', 'measurements'])
            ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $this->visibleActivityIds($request)))
            ->get()
            ->map(fn (Indicator $indicator) => $indicator->toArray() + [
                'agrege' => ! in_array($indicator->aggregation, [null, 'non_aggregatable'], true),
            ])]);
    }

    /**
     * Évolution d’un indicateur période par période (description S&E §62-63,
     * §75) : cible, valeur validée, taux et statut de performance. Seules les
     * valeurs validées en vigueur sont tracées.
     */
    public function indicatorEvolution(Indicator $indicator, IndicatorCalculationService $calculator): JsonResponse
    {
        $this->authorize('view', $indicator);
        $targets = $indicator->targets()->get()->keyBy('monitoring_period_id');
        $values = $indicator->measurements()
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNull('superseded_at')
            ->orderByDesc('version')
            ->get()
            ->unique('monitoring_period_id')
            ->keyBy('monitoring_period_id');
        $periodIds = $targets->keys()->merge($values->keys())->unique();

        $points = MonitoringPeriod::query()->whereKey($periodIds->all())->orderBy('opens_on')->get()
            ->map(function (MonitoringPeriod $period) use ($targets, $values, $indicator, $calculator) {
                $target = $targets->get($period->id)?->value;
                $value = $values->get($period->id);
                $rate = $value?->attainment_rate ?? $calculator->attainment($indicator->direction, $target !== null ? (float) $target : null, $value?->value);

                return [
                    'periode' => $period->code,
                    'libelle' => $period->label,
                    'debut' => $period->opens_on,
                    'cible' => $target !== null ? (float) $target : null,
                    'realise' => $value?->value,
                    'taux' => $rate,
                    'statut' => $calculator->performanceStatus($rate),
                    'version' => $value?->version,
                ];
            })
            ->values();

        return response()->json([
            'indicateur' => [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'libelle' => $indicator->label,
                'unite' => $indicator->unit,
                'sens' => $indicator->direction,
                'reference' => $indicator->baseline_value,
            ],
            'seuils' => $calculator->thresholds(),
            'data' => $points,
        ]);
    }

    public function storeTarget(Request $request, Indicator $indicator): JsonResponse
    {
        $this->authorize('view', $indicator);
        $data = $request->validate([
            'monitoring_period_id' => ['required', 'integer', 'exists:monitoring_periods,id'],
            'value' => ['required', 'numeric'],
        ]);

        return response()->json(['data' => $this->monitoring->target($request->user(), $indicator, $data['monitoring_period_id'], (float) $data['value'])], 201);
    }

    public function storeMeasurement(StoreIndicatorMeasurementRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->monitoring->measure($request->user(), $request->validated())], 201);
    }

    public function submitMeasurement(IndicatorMeasurement $measurement): JsonResponse
    {
        $this->authorize('view', $measurement);

        return response()->json(['data' => $this->monitoring->transitionMeasurement(request()->user(), $measurement, 'soumettre')]);
    }

    public function validateMeasurement(IndicatorMeasurement $measurement): JsonResponse
    {
        $this->authorize('validate', $measurement);

        return response()->json(['data' => $this->monitoring->transitionMeasurement(request()->user(), $measurement, 'valider')]);
    }

    /**
     * Rejet ou retour en correction d’une mesure soumise, avec motif.
     */
    public function decideMeasurement(Request $request, IndicatorMeasurement $measurement, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['rejeter', 'corriger', 'consolider'], true), 404);
        $this->authorize('validate', $measurement);
        $data = $request->validate(['motif' => [$decision === 'consolider' ? 'nullable' : 'required', 'string', 'max:255']]);

        return response()->json(['data' => $this->monitoring->transitionMeasurement($request->user(), $measurement, $decision, $data['motif'] ?? null)]);
    }

    /**
     * Transitions d’une réalisation physique : soumettre, valider, rejeter,
     * corriger (retour à l’auteur).
     */
    public function transitionAchievement(Request $request, PhysicalAchievement $achievement, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['soumettre', 'valider', 'rejeter', 'corriger', 'consolider'], true), 404);
        $this->authorize('view', $achievement);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->monitoring->transitionAchievement($request->user(), $achievement, $decision, $data['motif'] ?? null)]);
    }

    public function correctMeasurement(Request $request, IndicatorMeasurement $measurement): JsonResponse
    {
        $this->authorize('view', $measurement);
        $data = $request->validate([
            'value' => ['required', 'numeric'],
            'motif' => ['required', 'string'],
        ]);

        return response()->json(['data' => $this->monitoring->correctMeasurement($request->user(), $measurement, (float) $data['value'], $data['motif'])], 201);
    }

    public function storeAchievement(SubmitPhysicalAchievementRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->monitoring->achieve($request->user(), $request->validated())], 201);
    }

    public function storeVariance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pap_enrichment_id' => ['required', 'integer', 'exists:pap_enrichments,id'],
            'kind' => ['required', 'string'],
            'cause_category' => ['nullable', 'string'],
            'cause' => ['nullable', 'string'],
            'consequence' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            'responsible_role' => ['required', 'string'],
            'due_on' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->monitoring->variance($request->user(), $data)], 201);
    }

    public function variances(Request $request, VarianceDossierService $dossiers): JsonResponse
    {
        $this->authorize('viewAny', PerformanceVariance::class);
        $dossiers->generateAlerts();
        $perPage = min(50, max(1, (int) $request->integer('per_page', 8)));
        $page = PerformanceVariance::query()
            ->with('activity:id,code,activite')
            ->whereIn('pap_enrichment_id', $this->visibleActivityIds($request))
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => $this->pageMeta($page->currentPage(), $page->lastPage(), $page->total(), $page->perPage()),
        ]);
    }

    public function updateCorrective(Request $request, CorrectiveAction $correctiveAction): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(CorrectiveAction::STATUSES)],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => app(FollowUpService::class)->updateCorrective($request->user(), $correctiveAction, $data)]);
    }

    public function reviewRisk(Request $request, SeRisk $risk): JsonResponse
    {
        $data = $request->validate([
            'probability' => ['nullable', 'integer', 'min:1', 'max:4'],
            'impact' => ['nullable', 'integer', 'min:1', 'max:4'],
            'status' => ['nullable', Rule::in(SeRisk::STATUSES)],
            'prevention' => ['nullable', 'string', 'max:2000'],
            'mitigation' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $risk = app(FollowUpService::class)->reviewRisk($request->user(), $risk, $data);

        return response()->json(['data' => $risk->toArray() + ['criticite' => $risk->criticite()]]);
    }

    public function updateRecommendation(Request $request, SeRecommendation $recommendation): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(SeRecommendation::STATUSES)],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $row = app(FollowUpService::class)->updateRecommendation($request->user(), $recommendation, $data);

        return response()->json(['data' => $row->toArray() + ['en_retard' => $row->late()]]);
    }

    public function correctives(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CorrectiveAction::class);
        $rows = CorrectiveAction::query()
            ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $this->visibleActivityIds($request)))
            ->latest('id')
            ->get()
            ->map(fn (CorrectiveAction $row) => $row->toArray() + ['en_retard' => $row->late()]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Historique d’un élément suivi, reconstitué depuis le journal d’audit.
     */
    public function followUpHistory(Request $request, string $type, int $id): JsonResponse
    {
        $map = [
            'mesures-correctives' => [CorrectiveAction::class, 'corrective_action'],
            'risques' => [SeRisk::class, 'se_risk'],
            'recommandations' => [SeRecommendation::class, 'se_recommendation'],
        ];
        abort_unless(isset($map[$type]), 404);
        [$class, $objectType] = $map[$type];
        $model = $class::query()->findOrFail($id);
        $this->authorize('view', $model);

        return response()->json(['data' => AuditEvent::query()
            ->with('actor')
            ->where('object_type', $objectType)
            ->where('object_id', (string) $id)
            ->latest('id')
            ->get()
            ->map(fn (AuditEvent $event) => [
                'action' => $event->action,
                'avant' => $event->before,
                'apres' => $event->after,
                'motif' => $event->motif,
                'acteur' => $event->actor?->name,
                'le' => $event->created_at?->toDateTimeString(),
            ])]);
    }

    /**
     * @return Collection<int, int>
     */
    private function visibleActivityIds(Request $request): Collection
    {
        return $this->monitoring->visible($request->user())->pluck('pap_enrichments.id');
    }

    public function storeCorrective(StoreCorrectiveActionRequest $request): JsonResponse
    {
        $this->authorize('create', CorrectiveAction::class);

        return response()->json(['data' => $this->monitoring->corrective($request->user(), $request->validated())], 201);
    }

    public function risks(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SeRisk::class);
        $rows = SeRisk::query()
            ->whereIn('pap_enrichment_id', $this->visibleActivityIds($request))
            ->latest('id')
            ->get()
            ->map(fn (SeRisk $risk) => $risk->toArray() + ['criticite' => $risk->criticite()]);

        return response()->json(['data' => $rows]);
    }

    public function storeRisk(StoreRiskRequest $request): JsonResponse
    {
        $risk = $this->monitoring->risk($request->user(), $request->validated());

        return response()->json(['data' => $risk->toArray() + ['criticite' => $risk->criticite()]], 201);
    }

    public function storeRecommendation(Request $request): JsonResponse
    {
        $this->authorize('create', SeRecommendation::class);
        $data = $request->validate([
            'origin' => ['required', 'string'],
            'description' => ['required', 'string'],
            'responsible_role' => ['required', 'string'],
            'due_on' => ['nullable', 'date'],
            'priority' => ['nullable', 'string'],
            'pap_enrichment_id' => ['nullable', 'integer', 'exists:pap_enrichments,id'],
        ]);

        return response()->json(['data' => $this->monitoring->recommendation($request->user(), $data)], 201);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SeRecommendation::class);
        $rows = SeRecommendation::query()
            ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $this->visibleActivityIds($request)))
            ->latest('id')
            ->get()
            ->map(fn (SeRecommendation $row) => $row->toArray() + ['en_retard' => $row->late()]);

        return response()->json(['data' => $rows]);
    }

    public function evaluations(): JsonResponse
    {
        $this->authorize('viewAny', SeEvaluation::class);

        return response()->json(['data' => SeEvaluation::query()->latest('id')->get()]);
    }

    public function storeEvaluation(StoreEvaluationRequest $request): JsonResponse
    {
        $this->authorize('create', SeEvaluation::class);

        return response()->json(['data' => $this->monitoring->evaluation($request->validated())], 201);
    }

    public function periods(Request $request): JsonResponse
    {
        $directeur = $request->user()?->holds('directeur_budget') ?? false;

        return response()->json([
            'data' => MonitoringPeriod::query()->orderBy('opens_on')->get()->map(fn (MonitoringPeriod $period): array => [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'status' => $period->status,
                'opens_on' => $period->opens_on?->toDateString(),
                'closes_on' => $period->closes_on?->toDateString(),
                'peut_consolider' => $directeur && $period->status !== 'consolidee',
            ])->values(),
        ]);
    }

    public function closePeriod(Request $request, MonitoringPeriod $period): JsonResponse
    {
        $period = $this->campaigns->consolider($request->user(), $period);

        return response()->json(['data' => ['id' => $period->id, 'statut' => $period->status]]);
    }

    public function consolidate(Request $request): JsonResponse
    {
        $level = $request->validate(['niveau' => ['required', 'in:pilier,axe,produit,sous_produit,activite']])['niveau'];

        return response()->json(['data' => $this->monitoring->consolidate($request->user(), $level)]);
    }

    public function gantt(GanttService $gantt): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);

        return response()->json(['data' => $gantt->timeline($this->monitoring->visible(request()->user())->get())]);
    }

    public function portfolioGantt(Request $request, PortfolioGanttService $portfolio): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);
        $filters = $request->validate([
            'annee' => ['nullable', 'integer', 'between:2000,2100'],
            'periode' => ['nullable', 'regex:/^(annee|S[12]|T[1-4]|M(0[1-9]|1[0-2]))$/'],
            'unite_id' => ['nullable', 'integer'],
            'pilier' => ['nullable', 'string', 'max:255'],
            'etat' => ['nullable', 'in:a_venir,en_cours,en_retard,termine,indicative'],
            'recherche' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $portfolio->build($request->user(), $filters)]);
    }

    public function aggregateIndicators(IndicatorAggregationService $aggregation): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);
        $ids = $this->monitoring->visible(request()->user())->pluck('id');
        $indicators = Indicator::query()->with('measurements')->whereIn('pap_enrichment_id', $ids)->get();

        return response()->json(['data' => $aggregation->aggregate($indicators)]);
    }

    public function schedule(Request $request, PapTask $papTask): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'actual_start' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date'],
            'depends_on_id' => ['nullable', 'integer', 'exists:pap_tasks,id'],
        ]);

        return response()->json(['data' => $this->monitoring->schedule($request->user(), $papTask, $data)]);
    }

    public function referentials(ReferentialService $referentials, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['cause', 'critere', 'score'], true), 404);

        return response()->json(['data' => $referentials->list($kind)]);
    }

    public function storeReferential(Request $request, ReferentialService $referentials, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['cause', 'critere'], true), 404);
        $referentials->assertKeeper($request->user());
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $referentials->store($kind, $data)], 201);
    }

    public function updateReferential(Request $request, ReferentialService $referentials, SeReferential $referential): JsonResponse
    {
        $referentials->assertKeeper($request->user());
        $data = $request->validate([
            'label' => ['sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $referentials->update($referential, $data)]);
    }

    public function updateScore(Request $request, ReferentialService $referentials): JsonResponse
    {
        $referentials->assertKeeper($request->user());
        $data = $request->validate([
            'composantes' => ['required', 'array', 'min:1'],
            'composantes.*.code' => ['required', 'string'],
            'composantes.*.label' => ['required', 'string'],
            'composantes.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $referentials->replaceScore($data['composantes']);

        return response()->json(['data' => $referentials->list('score')]);
    }

    public function proof(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:mesure,realisation,mesure_corrective,recommandation,risque'],
            'id' => ['required', 'integer'],
            'category' => ['required', 'string'],
            'fichier' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', config('ged.extensions')), 'mimes:'.implode(',', config('ged.extensions'))],
        ]);
        $model = match ($data['type']) {
            'mesure' => IndicatorMeasurement::query()->findOrFail($data['id']),
            'realisation' => PhysicalAchievement::query()->findOrFail($data['id']),
            'mesure_corrective' => CorrectiveAction::query()->findOrFail($data['id']),
            'recommandation' => SeRecommendation::query()->findOrFail($data['id']),
            default => SeRisk::query()->findOrFail($data['id']),
        };
        $this->authorize('view', $model);
        $proof = $this->monitoring->proof($request->user(), $model->getMorphClass(), $model->id, $data['category'], $request->file('fichier'));

        return response()->json(['data' => $proof], 201);
    }

    public function report(Request $request): Response
    {
        $this->authorize('viewAny', Indicator::class);
        $board = $this->monitoring->dashboard($request->user());
        $format = $request->query('format', 'json');
        $rows = collect($board['activites_detail']);

        if ($format === 'csv') {
            $lines = ['Activité;Structure;Physique;Financier;Écart;Payé'];
            foreach ($rows as $row) {
                $lines[] = implode(';', [$row['activite'], $row['structure'], $row['physique'], $row['financier'], $row['ecart'], $row['finances']['paye'] ?? 0]);
            }

            return response(implode("\n", $lines), 200, ['Content-Type' => 'text/csv']);
        }

        if ($format === 'xlsx') {
            return Excel::download(new MonitoringReportExport($rows), 'suivi-evaluation.xlsx');
        }

        if ($format === 'pdf') {
            // Document de travail : non archivé. Les rapports officiels passent par
            // /suivi/rapports-performance (snapshot, validation, publication).
            return Pdf::loadView('suivi.rapport', ['board' => $board])->download('suivi-evaluation-document-de-travail.pdf');
        }

        return response()->json(['data' => $board]);
    }

    /**
     * File de travail S&E : mesures et réalisations du périmètre, avec les
     * actions que l’utilisateur peut réellement effectuer (même règles que
     * le service : l’auteur soumet, un autre acteur valide).
     */
    public function workQueue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);
        $user = $request->user();
        $visible = $this->visibleActivityIds($request);
        $proofs = fn (string $type) => SeProof::query()->where('proofable_type', $type)->selectRaw('proofable_id, count(*) as total')->groupBy('proofable_id')->pluck('total', 'proofable_id');
        $measureProofs = $proofs((new IndicatorMeasurement)->getMorphClass());
        $achievementProofs = $proofs((new PhysicalAchievement)->getMorphClass());
        $actions = function ($row, int $proofCount) use ($user): array {
            $activity = $row instanceof IndicatorMeasurement ? ($row->indicator?->activity ?? new PapEnrichment) : ($row->activity ?? new PapEnrichment);
            $allowed = fn (string $action) => $this->monitoring->refusal($user, $activity, $row, $action) === null;
            $mine = $row->author_id === $user->id;
            $deciding = in_array($row->status, ['soumis', 'valide_responsable'], true);

            return [
                'preuve' => $mine && in_array($row->status, ['brouillon', 'a_corriger', 'soumis'], true),
                'soumettre' => $mine && in_array($row->status, ['brouillon', 'a_corriger'], true),
                'valider' => $deciding && $proofCount > 0 && $allowed('valider'),
                'rejeter' => $deciding && $allowed('rejeter'),
                'corriger' => $deciding && $allowed('corriger'),
                'consolider' => $row->status === 'valide' && $row->superseded_at === null && $allowed('consolider'),
                'rectifier' => in_array($row->status, ['valide', 'consolide'], true) && $row->superseded_at === null,
            ];
        };

        $measurements = IndicatorMeasurement::query()->with(['indicator.activity', 'period', 'author'])
            ->whereNull('superseded_at')
            ->whereHas('indicator', fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $visible))
            ->latest('id')->get()
            ->map(fn (IndicatorMeasurement $row) => [
                'type' => 'mesure',
                'id' => $row->id,
                'reference' => 'MES-'.$row->id,
                'objet' => $row->indicator?->code.' · '.$row->indicator?->label,
                'activite' => $row->indicator?->activity?->activite,
                'periode' => $row->period?->label,
                'valeur' => $row->value,
                'taux' => $row->attainment_rate,
                'version' => $row->version,
                'statut' => $row->status,
                'motif' => $row->rejection_motif,
                'auteur' => $row->author?->name,
                'preuves' => (int) ($measureProofs[$row->id] ?? 0),
                'actions' => $actions($row, (int) ($measureProofs[$row->id] ?? 0)),
            ]);

        $achievements = PhysicalAchievement::query()->with(['activity', 'task', 'author'])
            ->whereNull('superseded_at')
            ->whereIn('pap_enrichment_id', $visible)
            ->latest('id')->get()
            ->map(fn (PhysicalAchievement $row) => [
                'type' => 'realisation',
                'id' => $row->id,
                'reference' => 'REA-'.$row->id,
                'objet' => $row->task?->label ?? 'Activité entière',
                'activite' => $row->activity?->activite,
                'periode' => MonitoringPeriod::query()->whereKey($row->monitoring_period_id)->value('label'),
                'valeur' => $row->progress_percent,
                'taux' => $row->progress_percent,
                'version' => 1,
                'statut' => $row->status,
                'motif' => $row->rejection_motif,
                'auteur' => $row->author?->name,
                'preuves' => (int) ($achievementProofs[$row->id] ?? 0),
                'actions' => [...$actions($row, (int) ($achievementProofs[$row->id] ?? 0)), 'rectifier' => false],
            ]);

        $rows = $measurements->concat($achievements)->values();
        $perPage = min(50, max(1, (int) $request->integer('per_page', 8)));
        $page = max(1, (int) $request->integer('page', 1));
        $total = $rows->count();

        return response()->json([
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => $this->pageMeta($page, max(1, (int) ceil($total / $perPage)), $total, $perPage),
        ]);
    }

    /**
     * @return array{current_page: int, last_page: int, total: int, per_page: int}
     */
    private function pageMeta(int $current, int $last, int $total, int $perPage): array
    {
        return [
            'current_page' => $current,
            'last_page' => $last,
            'total' => $total,
            'per_page' => $perPage,
        ];
    }

    public function performanceReports(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);
        $rows = PerformanceReport::query()->with(['generatedBy', 'validatedBy', 'period'])
            ->when(! in_array($request->user()->role, ReportingService::VALIDATORS, true), fn ($query) => $query
                ->where(fn ($inner) => $inner->where('generated_by', $request->user()->id)->orWhere('status', 'publie')))
            ->latest('id')
            ->get()
            ->map(fn (PerformanceReport $report) => $this->reportSummary($report));

        return response()->json(['data' => $rows]);
    }

    public function storePerformanceReport(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);
        $data = $request->validate([
            'kind' => ['required', Rule::in(PerformanceReport::KINDS)],
            'monitoring_period_id' => ['nullable', 'integer', 'exists:monitoring_periods,id'],
            'commentaire' => ['nullable', 'string', 'max:5000'],
        ]);
        $report = app(ReportingService::class)->generate($request->user(), $data['kind'], $data['monitoring_period_id'] ?? null, $data['commentaire'] ?? null);

        return response()->json(['data' => $this->reportSummary($report) + ['snapshot' => $report->snapshot]], 201);
    }

    public function showPerformanceReport(Request $request, PerformanceReport $report): JsonResponse
    {
        $this->assertCanReadReport($request, $report);

        return response()->json(['data' => $this->reportSummary($report->load(['generatedBy', 'validatedBy', 'period'])) + ['snapshot' => $report->snapshot]]);
    }

    public function transitionPerformanceReport(Request $request, PerformanceReport $report, string $etape): JsonResponse
    {
        $this->assertCanReadReport($request, $report);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);
        $report = app(ReportingService::class)->transition($request->user(), $report, $etape, $data['motif'] ?? null);

        return response()->json(['data' => $this->reportSummary($report->load(['generatedBy', 'validatedBy', 'period']))]);
    }

    public function revisePerformanceReport(Request $request, PerformanceReport $report): JsonResponse
    {
        $this->assertCanReadReport($request, $report);
        $data = $request->validate(['commentaire' => ['nullable', 'string', 'max:5000']]);
        $report = app(ReportingService::class)->revise($request->user(), $report, $data['commentaire'] ?? null);

        return response()->json(['data' => $this->reportSummary($report)], 201);
    }

    public function performanceReportPdf(Request $request, PerformanceReport $report): Response
    {
        $this->assertCanReadReport($request, $report);
        abort_unless($report->status === 'publie', 422, 'Seul un rapport publié dispose d’un PDF officiel.');
        $documents = app(OfficialDocumentService::class);

        return $documents->download(app(ReportingService::class)->archive($report, $request->user()));
    }

    private function assertCanReadReport(Request $request, PerformanceReport $report): void
    {
        $user = $request->user();
        abort_unless(
            $user->holds(...ReportingService::VALIDATORS) || $report->generated_by === $user->id || $report->status === 'publie',
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reportSummary(PerformanceReport $report): array
    {
        return [
            'id' => $report->id,
            'reference' => $report->reference,
            'version' => $report->version,
            'type' => $report->kind,
            'titre' => $report->title,
            'periode' => $report->period?->label,
            'situation_au' => $report->situation_au?->toDateString(),
            'statut' => $report->status,
            'motif_retour' => $report->return_motif,
            'etabli_par' => $report->generatedBy?->name,
            'valide_par' => $report->validatedBy?->name,
            'valide_le' => $report->validated_at?->toDateTimeString(),
            'publie_le' => $report->published_at?->toDateTimeString(),
            'remplace' => $report->supersedes_id,
        ];
    }
}
