<?php

namespace App\Domains\Monitoring\Http\Controllers;

use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\SeDecision;
use App\Domains\Monitoring\Models\SeMilestone;
use App\Domains\Monitoring\Models\SePlanningRevision;
use App\Domains\Monitoring\Services\ActivityGanttService;
use App\Domains\Monitoring\Services\ActivitySheetService;
use App\Domains\Monitoring\Services\MeasurementEntryService;
use App\Domains\Monitoring\Services\MonitoringService;
use App\Domains\Monitoring\Services\PerformanceDashboardService;
use App\Domains\Monitoring\Services\SyntheseService;
use App\Domains\Monitoring\Services\VarianceDossierService;
use App\Domains\PAP\Models\PapEnrichment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Support\TransitionLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Écrans de la maquette Suivi-Évaluation (docs/maquette-SE) : tableau de
 * bord, fiche 360°, Gantt, saisie, écart, synthèse exécutive. Le contrôleur
 * valide et autorise ; les calculs sont dans les services.
 */
class SePagesController extends Controller
{
    public function __construct(private readonly MonitoringService $monitoring) {}

    public function dashboard(Request $request, PerformanceDashboardService $dashboard, VarianceDossierService $dossiers): JsonResponse
    {
        abort_if(! $request->user()->holdsAny(), 403);
        $filters = $request->validate([
            'exercice' => ['nullable', 'integer'],
            'periode' => ['nullable', 'integer', 'exists:monitoring_periods,id'],
            'structure' => ['nullable', 'integer', 'exists:organization_units,id'],
            'programme' => ['nullable', 'string', 'max:255'],
            'pilier' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['pap', 'hors_pap'])],
            'statut' => ['nullable', Rule::in(PerformanceDashboardService::STATUSES)],
            'responsable' => ['nullable', 'integer'],
            'attention_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $dossiers->generateAlerts();

        return response()->json(['data' => $dashboard->build($request->user(), array_filter($filters, fn ($value) => $value !== null && $value !== ''))]);
    }

    public function sheet(Request $request, PapEnrichment $papEnrichment, ActivitySheetService $sheets): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), $papEnrichment);

        return response()->json(['data' => $sheets->sheet($papEnrichment)]);
    }

    /**
     * Pilotage de l’activité : responsable désigné, dates réelles, statut
     * explicite (suspendue, bloquée, annulée, clôturée).
     */
    public function steer(Request $request, PapEnrichment $papEnrichment): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), $papEnrichment);
        abort_unless($request->user()->holds('directeur', 'directeur_budget', 'responsable_se', 'administrateur_fonctionnel', 'commissaire', 'secretaire_general'), 403);
        $data = $request->validate([
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'actual_start' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date', 'after_or_equal:actual_start'],
            'se_status' => ['nullable', Rule::in(['suspendue', 'bloquee', 'annulee', 'cloturee'])],
            'motif' => ['required_with:se_status', 'nullable', 'string', 'max:255'],
        ]);

        $activity = TransitionLock::run($papEnrichment, function (PapEnrichment $activity) use ($request, $data) {
            $before = $activity->only(['responsible_user_id', 'actual_start', 'actual_end', 'se_status']);
            $activity->forceFill([
                'responsible_user_id' => array_key_exists('responsible_user_id', $data) ? $data['responsible_user_id'] : $activity->responsible_user_id,
                'actual_start' => array_key_exists('actual_start', $data) ? $data['actual_start'] : $activity->actual_start,
                'actual_end' => array_key_exists('actual_end', $data) ? $data['actual_end'] : $activity->actual_end,
                'se_status' => array_key_exists('se_status', $data) ? $data['se_status'] : $activity->se_status,
                'se_status_motif' => $data['motif'] ?? $activity->se_status_motif,
            ])->save();
            FinancialAudit::record($request->user(), 'se.activite.piloter', 'pap_enrichment', (string) $activity->id, $this->scalar($before), $this->scalar($activity->only(array_keys($before))), $data['motif'] ?? null);

            return $activity;
        });

        return response()->json(['data' => $this->monitoring->activityCard($activity->fresh(['budgetLine.organizationUnit', 'tasks', 'responsible']))]);
    }

    public function gantt(Request $request, PapEnrichment $papEnrichment, ActivityGanttService $gantt): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), $papEnrichment);
        $scale = $request->validate(['echelle' => ['nullable', Rule::in(['semaines', 'mois', 'trimestres'])]])['echelle'] ?? 'mois';

        return response()->json(['data' => $gantt->build($papEnrichment, $scale)]);
    }

    public function proposePlanning(Request $request, PapEnrichment $papEnrichment, ActivityGanttService $gantt): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), $papEnrichment);
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:2000'],
            'taches' => ['required', 'array', 'min:1'],
            'taches.*.id' => ['required', 'integer'],
            'taches.*.starts_on' => ['required', 'date'],
            'taches.*.ends_on' => ['required', 'date'],
        ]);

        return response()->json(['data' => $gantt->propose($request->user(), $papEnrichment, $data['motif'], $data['taches'])], 201);
    }

    public function decidePlanning(Request $request, SePlanningRevision $planningRevision, string $choix, ActivityGanttService $gantt): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), PapEnrichment::query()->findOrFail($planningRevision->pap_enrichment_id));
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $gantt->decide($request->user(), $planningRevision, $choix === 'valider', $data['motif'] ?? null)]);
    }

    public function storeMilestone(Request $request, PapEnrichment $papEnrichment): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), $papEnrichment);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'planned_on' => ['required', 'date'],
            'pap_task_id' => ['nullable', 'integer', Rule::exists('pap_tasks', 'id')->where('pap_enrichment_id', $papEnrichment->id)],
            'responsible_label' => ['nullable', 'string', 'max:120'],
        ]);
        $milestone = SeMilestone::query()->create($data + [
            'pap_enrichment_id' => $papEnrichment->id,
            'position' => (int) SeMilestone::query()->where('pap_enrichment_id', $papEnrichment->id)->max('position') + 1,
        ]);
        FinancialAudit::record($request->user(), 'se.jalon.creer', 'se_milestone', (string) $milestone->id, null, ['libelle' => $milestone->label, 'prevu' => $milestone->planned_on?->toDateString()]);

        return response()->json(['data' => $milestone], 201);
    }

    public function updateMilestone(Request $request, SeMilestone $milestone): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), PapEnrichment::query()->findOrFail($milestone->pap_enrichment_id));
        $data = $request->validate([
            'achieved_on' => ['nullable', 'date'],
            'proof_label' => ['required_with:achieved_on', 'nullable', 'string', 'max:255'],
        ]);
        $before = $milestone->only(['achieved_on', 'proof_label']);
        $milestone->forceFill($data)->save();
        FinancialAudit::record($request->user(), 'se.jalon.franchir', 'se_milestone', (string) $milestone->id, $this->scalar($before), $this->scalar($milestone->only(['achieved_on', 'proof_label'])));

        return response()->json(['data' => $milestone->fresh()]);
    }

    public function variance(Request $request, PerformanceVariance $variance, VarianceDossierService $dossiers): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), PapEnrichment::query()->findOrFail($variance->pap_enrichment_id));

        return response()->json(['data' => $dossiers->dossier($variance)]);
    }

    public function varianceAction(Request $request, PerformanceVariance $variance, string $operation, VarianceDossierService $dossiers): JsonResponse
    {
        $user = $request->user();
        $result = match ($operation) {
            'relancer' => $dossiers->remind($user, $variance),
            'escalader' => $dossiers->escalate($user, $variance),
            'explication' => $dossiers->explain(
                $user,
                $variance,
                ...array_values($request->validate([
                    'texte' => ['required', 'string', 'max:5000'],
                    'interpretations' => ['nullable', 'array'],
                    'interpretations.*' => ['string', 'max:64'],
                    'causes' => ['required', 'array', 'min:1'],
                    'causes.*' => ['string', 'max:64'],
                ]) + ['interpretations' => []]),
            ),
            'probleme' => $dossiers->openProblem($user, $variance, $request->validate([
                'nature' => ['required', 'string', 'max:255'],
                'impact' => ['nullable', 'string', 'max:2000'],
                'occurred_on' => ['required', 'date'],
                'se_risk_id' => ['nullable', 'integer'],
            ])),
            'action-corrective' => $dossiers->correctiveAction($user, $variance, $request->validate([
                'anomaly' => ['required', 'string', 'max:2000'],
                'cause' => ['required', 'string', 'max:2000'],
                'description' => ['required', 'string', 'max:2000'],
                'responsible_label' => ['required', 'string', 'max:160'],
                'responsible_role' => ['nullable', 'string', 'max:64'],
                'decided_on' => ['required', 'date'],
                'due_on' => ['required', 'date', 'after_or_equal:decided_on'],
                'expected_result' => ['nullable', 'string', 'max:2000'],
            ])),
            default => abort(404),
        };

        return response()->json(['data' => $dossiers->dossier($variance->fresh()), 'resultat' => $result], $operation === 'probleme' || $operation === 'action-corrective' ? 201 : 200);
    }

    public function synthese(Request $request, SyntheseService $synthese): JsonResponse
    {
        abort_if(! $request->user()->holdsAny(), 403);
        $situation = $request->validate(['situation' => ['nullable', 'string']])['situation'] ?? 'jour';
        if ($situation !== 'jour') {
            $report = PerformanceReport::query()->findOrFail((int) $situation);

            return response()->json(['data' => $synthese->frozen($report)]);
        }

        return response()->json(['data' => $synthese->today($request->user())]);
    }

    public function storeDecision(Request $request, SyntheseService $synthese): JsonResponse
    {
        abort_if(! $request->user()->holdsAny(), 403);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'responsible_label' => ['required', 'string', 'max:160'],
            'due_on' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(SeDecision::PRIORITIES)],
            'pap_enrichment_id' => ['nullable', 'integer', 'exists:pap_enrichments,id'],
            'performance_report_id' => ['nullable', 'integer', 'exists:performance_reports,id'],
        ]);
        if (! empty($data['pap_enrichment_id'])) {
            $this->monitoring->assertVisible($request->user(), PapEnrichment::query()->findOrFail($data['pap_enrichment_id']));
        }

        return response()->json(['data' => $synthese->propose($request->user(), $data)], 201);
    }

    public function decide(Request $request, SeDecision $seDecision, string $operation, SyntheseService $synthese): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $synthese->decide($request->user(), $seDecision, $operation, $data['note'] ?? null)]);
    }

    public function entry(Request $request, Indicator $indicator, MeasurementEntryService $entries): JsonResponse
    {
        $this->authorize('view', $indicator);
        $period = $request->validate(['periode' => ['nullable', 'integer', 'exists:monitoring_periods,id']])['periode'] ?? null;

        return response()->json(['data' => $entries->context($request->user(), $indicator, $period)]);
    }

    public function assignIndicator(Request $request, Indicator $indicator, MeasurementEntryService $entries): JsonResponse
    {
        $this->authorize('view', $indicator);
        $data = $request->validate([
            'responsible_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $indicator = $entries->designer($request->user(), $indicator, (int) $data['responsible_user_id']);

        return response()->json(['data' => ['responsable_id' => $indicator->responsible_user_id]]);
    }

    public function preview(Request $request, Indicator $indicator, MeasurementEntryService $entries): JsonResponse
    {
        $this->authorize('view', $indicator);
        $data = $request->validate([
            'monitoring_period_id' => ['required', 'integer', 'exists:monitoring_periods,id'],
            'numerator' => ['nullable', 'numeric', 'min:0'],
            'denominator' => ['nullable', 'numeric'],
            'value' => ['nullable', 'numeric'],
        ]);

        return response()->json(['data' => $entries->preview(
            $indicator,
            MonitoringPeriod::query()->findOrFail($data['monitoring_period_id']),
            isset($data['numerator']) ? (float) $data['numerator'] : null,
            isset($data['denominator']) ? (float) $data['denominator'] : null,
            isset($data['value']) ? (float) $data['value'] : null,
        )]);
    }

    public function updateMeasurement(Request $request, IndicatorMeasurement $measurement, MeasurementEntryService $entries): JsonResponse
    {
        $this->authorize('view', $measurement);
        $data = $request->validate([
            'value' => ['nullable', 'numeric'],
            'numerator' => ['nullable', 'numeric', 'min:0'],
            'denominator' => ['nullable', 'numeric'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:255'],
            'justification' => ['nullable', 'string', 'max:255'],
            'montant_paye' => ['prohibited'],
        ]);

        return response()->json(['data' => $entries->updateDraft($request->user(), $measurement, $data)]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scalar(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $values);
    }

    /**
     * Liste des utilisateurs pouvant être désignés responsables d’activité.
     */
    public function responsibles(Request $request, PapEnrichment $papEnrichment): JsonResponse
    {
        $this->monitoring->assertVisible($request->user(), $papEnrichment);
        $unit = $papEnrichment->budgetLine?->organization_unit_id;

        return response()->json(['data' => User::query()
            ->where(fn ($query) => $query->where('organization_unit_id', $unit)->orWhereIn('organization_unit_id', fn ($sub) => $sub->select('id')->from('organization_units')->where('parent_id', $unit)))
            ->orderBy('name')
            ->get(['id', 'name', 'function_title', 'role'])]);
    }
}
