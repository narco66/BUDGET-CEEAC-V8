<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\SeDecision;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Notifications\MonitoringAlert;
use App\Domains\Monitoring\Support\SeReference;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Notifications\RoleHolders;
use App\Shared\Support\TransitionLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Synthèse exécutive et revue de performance (maquette S&E, écran 6 ;
 * description §86-88). « Données du jour » est calculé ; « situation figée »
 * relit le snapshot d’un rapport publié, jamais recalculé.
 */
class SyntheseService
{
    /**
     * Rôles habilités à décider en revue de performance.
     *
     * @var list<string>
     */
    public const DECIDERS = ['ordonnateur', 'secretaire_general', 'commissaire', 'directeur_budget'];

    public function __construct(
        private readonly PerformanceDashboardService $dashboard,
        private readonly IndicatorCalculationService $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function today(User $user): array
    {
        $activities = $this->dashboard->activities($user, []);
        $board = $this->dashboard->build($user, []);
        $cards = collect($board['activites']);
        $total = max(1, $cards->count());
        $strip = $board['bandeau'];
        $indicators = $board['indicateurs'];
        $recommendations = SeRecommendation::query()
            ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $activities->pluck('id')))
            ->get();
        $risks = SeRisk::query()->whereIn('pap_enrichment_id', $activities->pluck('id'))->where('status', '!=', 'clos')->get();
        $revised = $cards->sum(fn (array $card) => 1);
        $index = $cards->isEmpty() ? null : round((float) $cards->avg('score'), 1);
        $meanGap = $cards->isEmpty() ? 0.0 : round((float) $cards->avg(fn (array $card) => abs((float) $card['ecart'])), 1);

        return [
            'situation' => ['type' => 'jour', 'au' => today()->toDateString()],
            'indice' => ['valeur' => $index, 'appreciation' => $this->calculator->appreciation($index)],
            'kpis' => [
                'physique' => $board['execution']['physique'],
                'financier' => $board['execution']['engage'],
                'indicateurs_atteints' => $indicators['repartition']['atteint'] ?? 0,
                'indicateurs_total' => $indicators['total'],
                'activites_achevees' => $strip['terminees'],
                'activites_en_retard' => $strip['en_retard'],
                'activites_total' => $cards->count(),
                'recommandations_realisees' => $recommendations->whereIn('status', ['realisee', 'cloturee'])->count(),
                'recommandations_emises' => $recommendations->count(),
                'risques_critiques' => $strip['risques_critiques'],
                'risques_non_traites' => $strip['risques_non_traites'],
                'ecart_moyen' => $meanGap,
                'ecart_moyen_niveau' => $this->calculator->gapLevel($meanGap)['niveau'],
                'taux_achevement' => round($strip['terminees'] / $total * 100, 1),
                'taux_retard' => round($strip['en_retard'] / $total * 100, 1),
                'taux_indicateurs' => $indicators['total'] > 0 ? round(($indicators['repartition']['atteint'] ?? 0) / $indicators['total'] * 100, 1) : null,
                'taux_recommandations' => $recommendations->isEmpty() ? null : round($recommendations->whereIn('status', ['realisee', 'cloturee'])->count() / $recommendations->count() * 100, 1),
            ],
            'ecarts' => collect($board['attention'])->take(5)->values()->all(),
            'risques' => $this->matrix($risks),
            'recommandations' => $recommendations
                ->sortBy(fn (SeRecommendation $row) => [$row->late() ? 0 : 1, ['haute' => 0, 'moyenne' => 1, 'normale' => 1, 'basse' => 2][$row->priority] ?? 1, $row->due_on?->timestamp ?? PHP_INT_MAX])
                ->take(5)
                ->map(fn (SeRecommendation $row) => [
                    'id' => $row->id,
                    'reference' => $row->reference,
                    'description' => $row->description,
                    'responsable' => $row->responsible_role,
                    'statut' => $this->recommendationStatus($row),
                    'echeance' => $row->due_on?->toDateString(),
                ])->values()->all(),
            'decisions' => $this->decisions($activities->pluck('id'), $user),
            'situations_figees' => $this->frozenSituations(),
            'activites_suivies' => $revised,
        ];
    }

    /**
     * Situation figée d’un rapport publié : le snapshot fait foi.
     *
     * @return array<string, mixed>
     */
    public function frozen(PerformanceReport $report): array
    {
        abort_unless($report->status === 'publie', 422, 'Seul un rapport publié constitue une situation figée.');
        $synthese = $report->snapshot['synthese_executive'] ?? null;
        abort_if($synthese === null, 422, 'Ce rapport a été publié avant l’intégration de la synthèse exécutive.');

        return array_merge($synthese, [
            'situation' => ['type' => 'figee', 'au' => $report->situation_au?->toDateString(), 'rapport_id' => $report->id, 'reference' => $report->reference, 'version' => $report->version],
            'situations_figees' => $this->frozenSituations(),
        ]);
    }

    /**
     * @param  Collection<int, SeRisk>  $risks
     * @return array<string, mixed>
     */
    public function matrix(Collection $risks): array
    {
        $cells = [];
        foreach (array_reverse(SeRisk::PROBABILITIES, true) as $probability => $probabilityLabel) {
            $row = [];
            foreach (SeRisk::IMPACTS as $impact => $impactLabel) {
                $row[] = [
                    'probabilite' => $probability,
                    'impact' => $impact,
                    'niveau' => SeRisk::MATRIX[$probability][$impact],
                    'nombre' => $risks->filter(fn (SeRisk $risk) => (int) $risk->probability === $probability && (int) $risk->impact === $impact)->count(),
                ];
            }
            $cells[] = ['probabilite' => $probabilityLabel, 'cases' => $row];
        }

        return [
            'ouverts' => $risks->count(),
            'lignes' => $cells,
            'impacts' => array_values(SeRisk::IMPACTS),
        ];
    }

    /**
     * @param  Collection<int, int>  $activityIds
     * @return array<string, mixed>
     */
    public function decisions(Collection $activityIds, User $user): array
    {
        $scope = SeDecision::query()
            ->where(fn ($query) => $query->whereNull('pap_enrichment_id')->orWhereIn('pap_enrichment_id', $activityIds));
        $rows = (clone $scope)
            ->orderByRaw("case status when 'attendue' then 0 when 'ajournee' then 1 when 'decidee' then 2 else 3 end")
            ->orderByRaw("case priority when 'haute' then 0 when 'moyenne' then 1 else 2 end")
            ->orderBy('due_on')
            ->limit(30)
            ->get();

        return [
            'attendues' => (clone $scope)->whereIn('status', ['attendue', 'ajournee'])->count(),
            'peut_decider' => $user->holds(...self::DECIDERS),
            'lignes' => $rows->map(fn (SeDecision $row) => [
                'id' => $row->id,
                'reference' => $row->reference,
                'description' => $row->description,
                'responsable' => $row->responsible_label,
                'echeance' => $row->due_on?->toDateString(),
                'priorite' => $row->priority,
                'statut' => $row->status,
                'note' => $row->decision_note,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array{description: string, responsible_label: string, due_on?: string|null, priority: string, pap_enrichment_id?: int|null, performance_report_id?: int|null}  $data
     */
    public function propose(User $user, array $data): SeDecision
    {
        if (! in_array($data['priority'], SeDecision::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Priorité inconnue.']);
        }

        return DB::transaction(function () use ($user, $data) {
            $decision = SeDecision::query()->create([
                ...$data,
                'reference' => SeReference::next('DEC'),
                'status' => 'attendue',
                'created_by' => $user->id,
            ]);
            FinancialAudit::record($user, 'se.decision.proposer', 'se_decision', (string) $decision->id, null, ['reference' => $decision->reference]);
            app(RoleHolders::class)->query(self::DECIDERS)->get()
                ->each(fn (User $target) => $target->notify(new MonitoringAlert('Décision attendue '.$decision->reference.' : '.str($decision->description)->limit(80), '/suivi/synthese')));

            return $decision;
        });
    }

    /**
     * Décider ou ajourner. Une décision prise devient une action suivie
     * (statut « décidée », puis « mise en œuvre »).
     */
    public function decide(User $user, SeDecision $decision, string $action, ?string $note): SeDecision
    {
        if (! $user->holds(...self::DECIDERS)) {
            throw ValidationException::withMessages(['action' => 'Cette décision revient aux autorités de la revue de performance.']);
        }
        if ($action === 'ajourner' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'L’ajournement doit être motivé.']);
        }

        return TransitionLock::run($decision, function (SeDecision $decision) use ($user, $action, $note) {
            $allowed = [
                'decider' => ['attendue', 'ajournee'],
                'ajourner' => ['attendue'],
                'mettre_en_oeuvre' => ['decidee'],
            ];
            if (! in_array($decision->status, $allowed[$action] ?? [], true)) {
                throw ValidationException::withMessages(['action' => 'Action impossible au statut « '.$decision->status.' ».']);
            }
            $from = $decision->status;
            $decision->forceFill([
                'status' => match ($action) {
                    'decider' => 'decidee',
                    'ajourner' => 'ajournee',
                    default => 'mise_en_oeuvre',
                },
                'decision_note' => $note ?? $decision->decision_note,
                'decided_by' => $action === 'decider' ? $user->id : $decision->decided_by,
                'decided_at' => $action === 'decider' ? now() : $decision->decided_at,
            ])->save();
            FinancialAudit::record($user, 'se.decision.'.$action, 'se_decision', (string) $decision->id, ['statut' => $from], ['statut' => $decision->status], $note);

            return $decision->fresh();
        });
    }

    private function recommendationStatus(SeRecommendation $row): string
    {
        return match (true) {
            in_array($row->status, ['realisee', 'cloturee'], true) => 'atteint',
            $row->status === 'rejetee' => 'rejetee',
            $row->late() => 'en_retard',
            default => 'en_bonne_voie',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frozenSituations(): array
    {
        return PerformanceReport::query()->where('status', 'publie')->latest('situation_au')->latest('id')->limit(10)->get()
            ->map(fn (PerformanceReport $report) => [
                'rapport_id' => $report->id,
                'reference' => $report->reference,
                'version' => $report->version,
                'au' => $report->situation_au?->toDateString(),
                'titre' => $report->title,
            ])->values()->all();
    }
}
