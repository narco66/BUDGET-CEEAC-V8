<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\IndicatorTarget;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\SeProof;
use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Notifications\RoleHolders;
use App\Shared\Support\TransitionLock;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Saisie et validation d’une valeur d’indicateur (maquette S&E, écran 4) :
 * contexte de l’indicateur, valeur calculée côté serveur, comparaison à la
 * cible et à la période précédente, circuit à quatre niveaux.
 */
class MeasurementEntryService
{
    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly IndicatorCalculationService $calculator,
        private readonly ActivitySheetService $sheets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, Indicator $indicator, ?int $periodId): array
    {
        $indicator->loadMissing(['activity.budgetLine.exercice', 'activity.budgetLine.organizationUnit', 'activity.responsible', 'responsible', 'targets.period', 'measurements.period']);
        $activity = $indicator->activity;
        $period = $periodId !== null
            ? MonitoringPeriod::query()->findOrFail($periodId)
            : (MonitoringPeriod::query()->whereDate('opens_on', '<=', today())->whereDate('closes_on', '>=', today())->orderBy('opens_on')->first()
                ?? MonitoringPeriod::query()->orderByDesc('opens_on')->first());
        $measurement = $period ? IndicatorMeasurement::query()->with('author')
            ->where('indicator_id', $indicator->id)
            ->where('monitoring_period_id', $period->id)
            ->whereNull('superseded_at')
            ->where('status', '!=', 'rejete')
            ->orderByDesc('version')
            ->first() : null;
        $row = $this->sheets->indicatorRow($indicator->setRelation('measurements', $indicator->measurements->whereIn('status', ['valide', 'consolide'])->whereNull('superseded_at')));
        $previous = $period ? $this->previous($indicator, $period) : null;

        return [
            'indicateur' => $row + [
                'numerateur' => $indicator->numerator_label,
                'denominateur' => $indicator->denominator_label,
                'ratio' => filled($indicator->numerator_label) && filled($indicator->denominator_label),
                'activite' => $activity ? ['id' => $activity->id, 'code' => $activity->code, 'libelle' => $activity->activite] : null,
                'responsable_id' => $indicator->responsible_user_id,
                'responsable' => $indicator->responsible?->name,
                'peut_designer' => $user->holds('directeur', 'directeur_budget', 'responsable_se', 'administrateur_fonctionnel', 'commissaire', 'secretaire_general'),
            ],
            'periode' => $period ? [
                'id' => $period->id,
                'code' => $period->code,
                'libelle' => $period->label,
                'debut' => $period->opens_on?->toDateString(),
                'fin' => $period->closes_on?->toDateString(),
                'echeance' => $period->closes_on?->toDateString(),
                'jours' => $period->closes_on ? (int) today()->diffInDays($period->closes_on, false) : null,
                'cible' => $this->target($indicator, $period),
            ] : null,
            'periodes' => MonitoringPeriod::query()->orderBy('opens_on')->get(['id', 'code', 'label']),
            'mesure' => $measurement ? $this->measurementPayload($user, $measurement, $activity) : null,
            'precedent' => $previous,
            'circuit' => $this->circuit($user, $measurement, $activity),
            'controles' => $period ? $this->qualityChecks($indicator, $period, $measurement, $previous) : [],
            'historique' => $this->history($indicator),
            'classement_ged' => 'Exercice '.($activity?->budgetLine?->exercice?->annee ?? today()->year).' › Suivi-Évaluation › '.($activity?->code ?? $indicator->code),
        ];
    }

    public function designer(User $actor, Indicator $indicator, int $userId): Indicator
    {
        if (! $actor->holds('directeur', 'directeur_budget', 'responsable_se', 'administrateur_fonctionnel', 'commissaire', 'secretaire_general')) {
            throw ValidationException::withMessages([
                'action' => 'Seul un responsable de pilotage désigne le responsable de l’indicateur.',
            ]);
        }
        if ($indicator->pap_enrichment_id) {
            $this->monitoring->assertVisible($actor, PapEnrichment::query()->findOrFail($indicator->pap_enrichment_id));
        }
        User::query()->findOrFail($userId);

        return TransitionLock::run($indicator, function (Indicator $indicator) use ($actor, $userId): Indicator {
            $before = $indicator->responsible_user_id;
            $indicator->forceFill(['responsible_user_id' => $userId])->save();
            FinancialAudit::record($actor, 'se.indicateur.responsable', 'indicator', (string) $indicator->id, ['responsible_user_id' => $before], ['responsible_user_id' => $userId]);

            return $indicator;
        });
    }

    /**
     * Valeur calculée, taux de réalisation, évolution et statut, sans rien
     * enregistrer : la formule n’existe qu’ici.
     *
     * @return array<string, mixed>
     */
    public function preview(Indicator $indicator, MonitoringPeriod $period, ?float $numerator, ?float $denominator, ?float $value): array
    {
        [$computed, $formula] = $this->compute($indicator, $numerator, $denominator, $value);
        $target = $this->target($indicator, $period);
        $rate = $this->calculator->attainment($indicator->direction, $target, $computed);
        $previous = $this->previous($indicator, $period);

        return [
            'valeur' => $computed,
            'formule' => $formula,
            'cible' => $target,
            'taux' => $rate,
            'statut' => $this->calculator->performanceStatus($rate),
            'evolution' => $computed !== null && $previous !== null && $previous['valeur'] !== null ? round($computed - (float) $previous['valeur'], 2) : null,
            'precedent' => $previous,
        ];
    }

    /**
     * @return array{0: float|null, 1: string|null}
     */
    public function compute(Indicator $indicator, ?float $numerator, ?float $denominator, ?float $value): array
    {
        if (filled($indicator->numerator_label) && filled($indicator->denominator_label)) {
            if ($numerator === null || $denominator === null) {
                return [null, null];
            }
            if ($denominator <= 0) {
                throw ValidationException::withMessages(['denominator' => 'Le dénominateur doit être strictement positif.']);
            }

            return [round($numerator / $denominator * 100, 4), $this->number($numerator).' / '.$this->number($denominator).' × 100'];
        }

        return [$value, null];
    }

    /**
     * Mise à jour d’un brouillon par son auteur (« Enregistrer le brouillon »).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(User $user, IndicatorMeasurement $measurement, array $data): IndicatorMeasurement
    {
        return TransitionLock::run($measurement, function (IndicatorMeasurement $measurement) use ($user, $data) {
            if (! in_array($measurement->status, ['brouillon', 'a_corriger'], true) || $measurement->author_id !== $user->id) {
                throw ValidationException::withMessages(['action' => 'Seul l’auteur modifie une saisie en brouillon ou à corriger.']);
            }
            $indicator = $measurement->indicator;
            [$value] = $this->compute($indicator, $data['numerator'] ?? null, $data['denominator'] ?? null, isset($data['value']) ? (float) $data['value'] : null);
            if ($value === null) {
                throw ValidationException::withMessages(['value' => 'La valeur de la période est obligatoire.']);
            }
            $before = $measurement->only(['value', 'numerator', 'denominator', 'comment', 'source', 'justification']);
            $period = MonitoringPeriod::query()->findOrFail($measurement->monitoring_period_id);
            $measurement->forceFill([
                'value' => $value,
                'numerator' => $data['numerator'] ?? null,
                'denominator' => $data['denominator'] ?? null,
                'comment' => $data['comment'] ?? $measurement->comment,
                'source' => $data['source'] ?? $measurement->source,
                'justification' => $data['justification'] ?? $measurement->justification,
                'attainment_rate' => $this->calculator->attainment($indicator->direction, $this->target($indicator, $period), $value),
            ])->save();
            FinancialAudit::record($user, 'se.mesure.modifier', 'indicator_measurement', (string) $measurement->id, $before, $measurement->only(array_keys($before)));

            return $measurement->fresh();
        });
    }

    public function target(Indicator $indicator, MonitoringPeriod $period): ?float
    {
        $value = IndicatorTarget::query()->where('indicator_id', $indicator->id)->where('monitoring_period_id', $period->id)->value('value');
        if ($value !== null) {
            return (float) $value;
        }
        $annual = IndicatorTarget::query()
            ->where('indicator_id', $indicator->id)
            ->whereHas('period', fn ($query) => $query->where('exercice_year', $period->exercice_year))
            ->get()
            ->sortByDesc(fn (IndicatorTarget $row) => $row->period?->closes_on?->timestamp ?? 0)
            ->first();

        return $annual?->value;
    }

    /**
     * Contrôles de qualité de la maquette (écran 4) : valeur, plage, évolution
     * plausible, source, doublon et preuve.
     *
     * @param  array<string, mixed>|null  $previous
     * @return list<array{code: string, libelle: string, ok: bool, detail: string|null}>
     */
    public function qualityChecks(Indicator $indicator, MonitoringPeriod $period, ?IndicatorMeasurement $measurement, ?array $previous): array
    {
        $value = $measurement?->value;
        $percent = $indicator->unit === '%';
        $evolution = $value !== null && $previous !== null && $previous['valeur'] !== null ? round($value - (float) $previous['valeur'], 2) : null;
        $alert = $this->calculator->evolutionAlert();
        $duplicates = IndicatorMeasurement::query()
            ->where('indicator_id', $indicator->id)
            ->where('monitoring_period_id', $period->id)
            ->whereNull('superseded_at')
            ->whereNotIn('status', ['rejete'])
            ->when($measurement, fn ($query) => $query->whereKeyNot($measurement->id))
            ->exists();
        $proofs = $measurement ? SeProof::query()->where('proofable_type', $measurement->getMorphClass())->where('proofable_id', $measurement->id)->count() : 0;

        return [
            ['code' => 'valeur', 'libelle' => 'Valeur renseignée', 'ok' => $value !== null, 'detail' => null],
            ['code' => 'plage', 'libelle' => 'Valeur dans la plage', 'ok' => $value !== null && $value >= 0 && (! $percent || $value <= 100), 'detail' => $percent ? '0 – 100 %' : '≥ 0'],
            ['code' => 'evolution', 'libelle' => 'Évolution plausible', 'ok' => $evolution === null || abs($evolution) <= $alert, 'detail' => $evolution === null ? 'pas de valeur précédente' : sprintf('%+s %s, seuil d’alerte %s', $this->number($evolution), $percent ? 'pts' : '', $this->number($alert).($percent ? ' pts' : ''))],
            ['code' => 'source', 'libelle' => 'Source indiquée', 'ok' => filled($measurement?->source), 'detail' => null],
            ['code' => 'doublon', 'libelle' => 'Absence de doublon', 'ok' => ! $duplicates, 'detail' => $duplicates ? 'une autre saisie existe pour '.$period->label : $period->label.' non saisi ailleurs'],
            ['code' => 'preuve', 'libelle' => 'Preuve jointe', 'ok' => $proofs > 0, 'detail' => $proofs > 0 ? $proofs.' pièce(s) archivée(s)' : 'obligatoire avant validation'],
        ];
    }

    /**
     * Historique des valeurs par période : dernière version en vigueur et
     * situation figée qui l’a capturée.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Indicator $indicator): array
    {
        $reports = PerformanceReport::query()->whereIn('status', ['valide', 'publie'])->whereNotNull('situation_au')->orderBy('situation_au')->get(['id', 'situation_au']);

        return IndicatorMeasurement::query()
            ->with('period')
            ->where('indicator_id', $indicator->id)
            ->whereNull('superseded_at')
            ->where('status', '!=', 'rejete')
            ->get()
            ->groupBy('monitoring_period_id')
            ->map(fn ($rows) => $rows->sortByDesc('version')->first())
            ->sortBy(fn (IndicatorMeasurement $row) => $row->period?->opens_on?->timestamp ?? 0)
            ->map(function (IndicatorMeasurement $row) use ($reports) {
                $closes = $row->period?->closes_on;
                $snapshot = in_array($row->status, ['valide', 'consolide'], true) && $closes
                    ? $reports->first(fn (PerformanceReport $report) => $report->situation_au->gte($closes))
                    : null;

                return [
                    'id' => $row->id,
                    'periode' => $row->period?->label,
                    'valeur' => $row->value,
                    'statut' => $row->status,
                    'valide_le' => ($row->consolidated_at ?? $row->validated_at)?->toDateString(),
                    'snapshot' => $snapshot?->situation_au?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previous(Indicator $indicator, MonitoringPeriod $period): ?array
    {
        $row = IndicatorMeasurement::query()
            ->where('indicator_id', $indicator->id)
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNull('superseded_at')
            ->whereHas('period', fn ($query) => $query->whereDate('opens_on', '<', $period->opens_on))
            ->with('period')
            ->get()
            ->sortByDesc(fn (IndicatorMeasurement $measurement) => $measurement->period?->opens_on?->timestamp ?? 0)
            ->first();

        return $row ? ['periode' => $row->period?->label, 'valeur' => $row->value, 'taux' => $row->attainment_rate] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function measurementPayload(User $user, IndicatorMeasurement $measurement, ?PapEnrichment $activity): array
    {
        $proofs = SeProof::query()->where('proofable_type', $measurement->getMorphClass())->where('proofable_id', $measurement->id)->latest('id')->get();
        $refusal = fn (string $action) => $this->monitoring->refusal($user, $activity ?? new PapEnrichment, $measurement, $action) === null;
        $deciding = in_array($measurement->status, ['soumis', 'valide_responsable'], true);

        return [
            'id' => $measurement->id,
            'statut' => $measurement->status,
            'version' => $measurement->version,
            'valeur' => $measurement->value,
            'numerateur' => $measurement->numerator,
            'denominateur' => $measurement->denominator,
            'commentaire' => $measurement->comment,
            'source' => $measurement->source,
            'justification' => $measurement->justification,
            'taux' => $measurement->attainment_rate,
            'motif_retour' => $measurement->rejection_motif,
            'auteur' => $measurement->author?->name,
            'est_auteur' => $measurement->author_id === $user->id,
            'preuves' => $proofs->map(fn (SeProof $proof) => [
                'id' => $proof->id,
                'nom' => basename($proof->path),
                'taille' => Storage::disk('local')->exists($proof->path) ? Storage::disk('local')->size($proof->path) : null,
                'empreinte' => $proof->sha256,
                'le' => $proof->created_at?->toDateString(),
            ])->values()->all(),
            'actions' => [
                'modifier' => $measurement->author_id === $user->id && in_array($measurement->status, ['brouillon', 'a_corriger'], true),
                'soumettre' => $measurement->author_id === $user->id && in_array($measurement->status, ['brouillon', 'a_corriger'], true),
                'valider' => $deciding && $proofs->isNotEmpty() && $refusal('valider'),
                'retourner' => $deciding && $refusal('corriger'),
                'rejeter' => $deciding && $refusal('rejeter'),
                'consolider' => $measurement->status === 'valide' && $refusal('consolider'),
            ],
        ];
    }

    /**
     * Les quatre étapes de la maquette et l’acteur attendu à chacune.
     *
     * @return list<array<string, mixed>>
     */
    private function circuit(User $user, ?IndicatorMeasurement $measurement, ?PapEnrichment $activity): array
    {
        $status = $measurement?->status ?? 'brouillon';
        $order = ['brouillon' => 0, 'a_corriger' => 0, 'soumis' => 1, 'valide_responsable' => 2, 'valide' => 3, 'consolide' => 4, 'rejete' => -1];
        $reached = $order[$status] ?? 0;
        $unitId = $activity?->budgetLine?->organization_unit_id;
        $director = User::query()->where('role', 'directeur')->when($unitId, fn ($query) => $query->where('organization_unit_id', $unitId))->first();
        $consolidator = app(RoleHolders::class)->query('responsable_se')->first()
            ?? app(RoleHolders::class)->query('directeur_budget')->first();
        $author = $measurement?->author ?? $user;
        $name = fn (?int $id) => $id ? User::query()->whereKey($id)->value('name') : null;

        $steps = [
            ['numero' => 1, 'etape' => 'Saisie', 'acteur' => $author->name, 'qualite' => $author->id === $user->id ? 'vous' : 'auteur', 'le' => $measurement?->submitted_at?->toDateString()],
            ['numero' => 2, 'etape' => 'Validation responsable', 'acteur' => $name($measurement?->responsible_validator_id) ?? $activity?->responsible?->name ?? 'Responsable d’activité', 'qualite' => 'responsable d’activité', 'le' => $measurement?->responsible_validated_at?->toDateString()],
            ['numero' => 3, 'etape' => 'Validation hiérarchique', 'acteur' => $name($measurement?->validator_id) ?? $director?->name ?? 'Directeur', 'qualite' => $director?->function_title ?? 'hiérarchie', 'le' => $measurement?->validated_at?->toDateString()],
            ['numero' => 4, 'etape' => 'Consolidation', 'acteur' => $name($measurement?->consolidated_by) ?? $consolidator?->name ?? 'Responsable S&E', 'qualite' => 'responsable S&E', 'le' => $measurement?->consolidated_at?->toDateString()],
        ];

        return array_map(fn (array $step) => $step + [
            'etat' => $reached < 0 ? ($step['numero'] === 1 ? 'fait' : 'rejete') : ($step['numero'] <= $reached ? 'fait' : ($step['numero'] === $reached + 1 ? 'en_cours' : 'a_venir')),
        ], $steps);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', ' '), '0'), ',');
    }
}
