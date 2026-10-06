<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use App\Shared\Support\TransitionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rapports de performance officiels. Le contenu est figé à la génération à
 * partir des mêmes calculs que le tableau de bord ; la validation est faite
 * par un second acteur ; la publication archive le PDF officiel.
 */
class ReportingService
{
    /**
     * @var list<string>
     */
    public const VALIDATORS = ['directeur', 'directeur_budget', 'controleur_financier', 'secretaire_general'];

    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly OfficialDocumentService $documents,
    ) {}

    public function generate(User $user, string $kind, ?int $periodId, ?string $comment, ?PerformanceReport $previous = null): PerformanceReport
    {
        if (! in_array($kind, PerformanceReport::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Type de rapport inconnu.']);
        }
        $period = $periodId !== null ? MonitoringPeriod::query()->findOrFail($periodId) : null;
        $board = $this->monitoring->dashboard($user);
        $visibleIds = collect($board['activites_detail'])->pluck('id');

        $snapshot = [
            'genere_le' => now()->toDateTimeString(),
            'perimetre' => $user->organizationUnit?->structureLabel() ?? 'Commission',
            'periode' => $period?->label,
            'synthese' => collect($board)->except('activites_detail')->all(),
            'activites' => collect($board['activites_detail'])->map(fn (array $row) => collect($row)->only([
                'id', 'activite', 'pilier', 'axe', 'produit', 'structure', 'physique', 'financier', 'ecart', 'alerte', 'finances',
            ])->all())->values()->all(),
            'risques_critiques' => SeRisk::query()->whereIn('pap_enrichment_id', $visibleIds)->where('status', '!=', 'clos')->get()
                ->filter(fn (SeRisk $risk) => $risk->criticite() === 'critique')
                ->map(fn (SeRisk $risk) => ['reference' => $risk->reference, 'description' => $risk->description, 'statut' => $risk->status])
                ->values()->all(),
            'recommandations_ouvertes' => SeRecommendation::query()
                ->whereNotIn('status', SeRecommendation::FINAL)
                ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $visibleIds))
                ->get()
                ->map(fn (SeRecommendation $row) => ['reference' => $row->reference, 'description' => $row->description, 'echeance' => $row->due_on?->toDateString(), 'en_retard' => $row->late()])
                ->values()->all(),
            // La synthèse exécutive est figée avec le rapport (maquette S&E, écran 6 : « situation figée »).
            'synthese_executive' => collect(app(SyntheseService::class)->today($user))->except('situations_figees')->all(),
        ];

        return DB::transaction(function () use ($user, $kind, $period, $comment, $snapshot, $previous) {
            $sequence = PerformanceReport::query()->lockForUpdate()->pluck('reference')->unique()->count() + 1;
            $reference = $previous?->reference ?? sprintf('RAP-SE-%d-%04d', now()->year, $sequence);
            $report = PerformanceReport::query()->create([
                'reference' => $reference,
                'version' => $previous !== null ? $previous->version + 1 : 1,
                'kind' => $kind,
                'title' => 'Rapport '.str_replace('_', '/', $kind).($period ? ' · '.$period->label : ''),
                'monitoring_period_id' => $period?->id,
                'situation_au' => today(),
                'status' => 'brouillon',
                'snapshot' => $snapshot,
                'commentaire' => $comment,
                'generated_by' => $user->id,
                'supersedes_id' => $previous?->id,
            ]);
            FinancialAudit::record($user, 'se.rapport.generer', 'performance_report', (string) $report->id, null, ['reference' => $reference, 'version' => $report->version]);

            return $report;
        });
    }

    /**
     * Nouvelle version d’un rapport validé ou publié : le précédent reste
     * consultable tel qu’il a été validé.
     */
    public function revise(User $user, PerformanceReport $report, ?string $comment): PerformanceReport
    {
        if (! in_array($report->status, ['valide', 'publie'], true)) {
            throw ValidationException::withMessages(['action' => 'Un brouillon se corrige directement ; seule une version validée donne lieu à une nouvelle version.']);
        }

        return $this->generate($user, $report->kind, $report->monitoring_period_id, $comment, $report);
    }

    public function transition(User $user, PerformanceReport $report, string $action, ?string $motif = null): PerformanceReport
    {
        $rule = PerformanceReport::TRANSITIONS[$action] ?? null;
        abort_if($rule === null, 404);

        $report = TransitionLock::run($report, function (PerformanceReport $report) use ($user, $action, $motif, $rule) {
            if (! in_array($report->status, $rule['from'], true)) {
                throw ValidationException::withMessages(['action' => 'Action impossible au statut « '.$report->status.' ».']);
            }
            if (in_array($action, ['valider', 'retourner', 'publier'], true) && ! $user->holds(...self::VALIDATORS)) {
                throw ValidationException::withMessages(['action' => 'La revue, la validation et la publication sont réservées aux responsables.']);
            }
            if ($action === 'valider' && $report->generated_by === $user->id) {
                throw ValidationException::withMessages(['action' => 'Séparation des fonctions : l’auteur du rapport ne le valide pas.']);
            }
            if ($action === 'retourner' && blank($motif)) {
                throw ValidationException::withMessages(['motif' => 'Le retour d’un rapport doit être motivé.']);
            }

            $from = $report->status;
            $report->forceFill(array_filter([
                'status' => $rule['to'],
                'reviewed_by' => in_array($action, ['retourner', 'valider'], true) ? $user->id : null,
                'validated_by' => $action === 'valider' ? $user->id : null,
                'validated_at' => $action === 'valider' ? now() : null,
                'published_at' => $action === 'publier' ? now() : null,
                'return_motif' => $action === 'retourner' ? $motif : null,
            ], fn ($value, $key) => $key === 'status' || $value !== null, ARRAY_FILTER_USE_BOTH))->save();
            FinancialAudit::record($user, 'se.rapport.'.$action, 'performance_report', (string) $report->id, ['statut' => $from], ['statut' => $report->status], $motif);

            return $report->fresh();
        });

        if ($action === 'publier') {
            $this->archive($report, $user);
        }

        return $report;
    }

    public function archive(PerformanceReport $report, ?User $user): GeneratedDocument
    {
        return $this->documents->current($report, 'rapport_se')
            ?? $this->documents->archive(
                $report,
                'rapport_se',
                $report->reference.'-v'.$report->version,
                'suivi.rapport',
                ['board' => $report->snapshot['synthese'] + ['activites_detail' => $report->snapshot['activites']], 'rapport' => $report->load(['generatedBy', 'validatedBy', 'period'])],
                'publication',
                $user,
            );
    }
}
