<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\SeProblem;
use App\Domains\Monitoring\Models\SeReferential;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Notifications\MonitoringAlert;
use App\Domains\Monitoring\Support\SeReference;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\Tasks\Services\TaskAudience;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Support\TransitionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écart critique et action corrective (maquette S&E, écran 5 ; description
 * §23-25, §34-36, §90). Une explication et une action corrective sont
 * obligatoires pour un écart critique ; chaque étape est datée et tracée.
 */
class VarianceDossierService
{
    /**
     * Interprétations proposées selon le sens de l’écart (description §24).
     *
     * @var array<string, array<string, string>>
     */
    public const INTERPRETATIONS = [
        'financier_superieur' => [
            'avance' => 'Avance importante versée',
            'engagement_anticipe' => 'Dépenses engagées avant réalisation',
            'surcout' => 'Surcoût',
            'retard_technique' => 'Retard technique',
        ],
        'physique_superieur' => [
            'factures_non_traitees' => 'Factures non encore traitées',
            'prestations_non_payees' => 'Prestations réalisées mais non payées',
            'contribution_non_monetaire' => 'Contribution non monétaire',
            'decalage_comptable' => 'Décalage de comptabilisation',
        ],
    ];

    public function __construct(private readonly MonitoringService $monitoring) {}

    /**
     * @return array<string, mixed>
     */
    public function dossier(PerformanceVariance $variance): array
    {
        $activity = PapEnrichment::query()->with(['budgetLine.organizationUnit.parent', 'tasks', 'responsible'])->findOrFail($variance->pap_enrichment_id);
        $card = $this->monitoring->activityCard($activity);
        $problem = SeProblem::query()->with('risk')->where('performance_variance_id', $variance->id)->latest('id')->first();
        $action = CorrectiveAction::query()->where('performance_variance_id', $variance->id)->latest('id')->first();
        $direction = $card['ecart'] < 0 ? 'financier_superieur' : 'physique_superieur';
        $report = $variance->reported_in ?? PerformanceReport::query()->where('status', 'publie')->where('created_at', '>=', $variance->created_at)->value('reference');

        return [
            'ecart' => [
                'id' => $variance->id,
                'reference' => $variance->reference,
                'statut' => $variance->status,
                'physique_alerte' => $variance->physical_rate,
                'financier_alerte' => $variance->financial_rate,
            ],
            'activite' => collect($card)->except(['taches', 'finances'])->all() + [
                'departement' => $activity->budgetLine?->organizationUnit?->parent?->name ?? $activity->budgetLine?->organizationUnit?->name,
            ],
            'sens' => $direction,
            'chronologie' => [
                ['etape' => 'Alerte générée', 'le' => $variance->created_at?->toDateString(), 'etat' => 'fait'],
                ['etape' => 'Explication demandée', 'le' => $variance->explanation_requested_at?->toDateString(), 'etat' => $variance->explanation_requested_at ? 'fait' : 'attendu'],
                ['etape' => 'Explication reçue', 'le' => $variance->explanation_received_at?->toDateString(), 'etat' => $variance->explanation_received_at ? 'fait' : 'attendu'],
                ['etape' => 'Problème ouvert', 'le' => $problem?->created_at?->toDateString(), 'etat' => $problem ? 'fait' : 'attendu'],
                ['etape' => 'Action corrective', 'le' => $action?->created_at?->toDateString(), 'etat' => $action ? 'fait' : 'attendu'],
                ['etape' => 'Suivi de l’action', 'le' => null, 'etat' => $action === null ? 'attendu' : ($action->late() ? 'en_retard' : (in_array($action->status, CorrectiveAction::FINAL, true) ? 'fait' : 'en_cours'))],
                ['etape' => $report ? 'Intégré au rapport '.$report : 'Intégré au prochain rapport', 'le' => null, 'etat' => $report ? 'fait' : 'a_venir'],
            ],
            'explication' => [
                'texte' => $variance->explanation,
                'auteur' => $variance->explainedBy?->name,
                'le' => $variance->explanation_received_at?->toDateString(),
                'interpretations' => $variance->interpretations ?? [],
                'causes' => $variance->cause_categories ?? array_values(array_filter([$variance->cause_category])),
            ],
            'options' => [
                'interpretations' => self::INTERPRETATIONS[$direction],
                'causes' => SeReferential::query()->where('kind', 'cause')->where('active', true)->orderBy('id')->get(['code', 'label']),
                'risques' => SeRisk::query()->where('pap_enrichment_id', $activity->id)->where('status', '!=', 'clos')->get(['id', 'reference', 'description', 'status']),
            ],
            'probleme' => $problem ? [
                'id' => $problem->id,
                'reference' => $problem->reference,
                'nature' => $problem->nature,
                'impact' => $problem->impact,
                'survenu_le' => $problem->occurred_on?->toDateString(),
                'statut' => $problem->status,
                'risque' => $problem->risk ? ['reference' => $problem->risk->reference, 'statut' => $problem->risk->status] : null,
            ] : null,
            'finances' => collect($card['finances'] ?? [])->only(['budget_revise', 'engage', 'liquide', 'paye', 'taux_engagement', 'taux_execution'])->all()
                + ['taux_liquide' => $this->rate($card['finances']['liquide'] ?? 0, $card['finances']['budget_revise'] ?? 0)]
                + ['taux_paye' => $this->rate($card['finances']['paye'] ?? 0, $card['finances']['budget_revise'] ?? 0)],
            'notifications' => $this->notifications($variance),
            'prochain_rapport' => $report ? null : MonitoringPeriod::query()->whereDate('closes_on', '>=', today())->orderBy('closes_on')->value('label'),
            'action' => $action ? [
                'id' => $action->id,
                'reference' => $action->reference,
                'anomalie' => $action->anomaly,
                'cause' => $action->cause,
                'description' => $action->description,
                'responsable' => $action->responsible_label ?? $action->responsible_role,
                'debut' => $action->decided_on?->toDateString(),
                'echeance' => $action->due_on?->toDateString(),
                'statut' => $action->status,
                'avancement' => $action->progress,
                'en_retard' => $action->late(),
                'jours_retard' => $action->late() ? (int) $action->due_on->diffInDays(today()) : 0,
                'resultat_attendu' => $action->expected_result,
            ] : null,
        ];
    }

    /**
     * Génère une alerte pour chaque activité en écart critique qui n’en a pas
     * déjà une ouverte, et demande l’explication au responsable.
     *
     * @return int nombre d’alertes créées
     */
    public function generateAlerts(): int
    {
        $this->monitoring->assignDefaultResponsibles();
        $created = 0;
        PapEnrichment::query()->with(['budgetLine.organizationUnit', 'tasks', 'responsible'])->get()
            ->each(function (PapEnrichment $activity) use (&$created): void {
                $card = $this->monitoring->activityCard($activity);
                if ($card['niveau_ecart'] !== 'critique') {
                    return;
                }
                if (PerformanceVariance::query()->where('pap_enrichment_id', $activity->id)->whereNotIn('status', ['clos'])->exists()) {
                    return;
                }
                $variance = PerformanceVariance::query()->create([
                    'reference' => $this->nextReference('EC'),
                    'pap_enrichment_id' => $activity->id,
                    'kind' => 'physique_financier',
                    'physical_rate' => $card['physique'],
                    'financial_rate' => $card['financier'],
                    'gap' => $card['ecart'],
                    'responsible_role' => $activity->responsible?->role ?? 'directeur',
                    'status' => 'critique',
                    'explanation_requested_at' => now(),
                ]);
                $this->notifyResponsible($activity, 'Écart critique de '.round(abs($card['ecart'])).' points sur '.$activity->activite.' : explication attendue.', $variance);
                $created++;
            });

        return $created;
    }

    public function remind(User $user, PerformanceVariance $variance): PerformanceVariance
    {
        return TransitionLock::run($variance, function (PerformanceVariance $variance) use ($user) {
            $activity = $this->activity($user, $variance);
            $variance->forceFill([
                'reminded_at' => now(),
                'explanation_requested_at' => $variance->explanation_requested_at ?? now(),
            ])->save();
            $this->notifyResponsible($activity, 'Relance : explication attendue sur l’écart '.$variance->reference.' ('.$activity->activite.').', $variance);
            FinancialAudit::record($user, 'se.ecart.relancer', 'performance_variance', (string) $variance->id, null, ['reference' => $variance->reference]);

            return $variance->fresh();
        });
    }

    public function escalate(User $user, PerformanceVariance $variance): PerformanceVariance
    {
        return TransitionLock::run($variance, function (PerformanceVariance $variance) use ($user) {
            $activity = $this->activity($user, $variance);
            $variance->forceFill(['escalated_at' => now()])->save();
            app(TaskAudience::class)->forRole('directeur', $activity->budgetLine?->organization_unit_id)
                ->each(fn (User $target) => $target->notify(new MonitoringAlert('Escalade : écart '.$variance->reference.' sur '.$activity->activite.' sans traitement suffisant.', '/suivi/ecarts/'.$variance->id)));
            FinancialAudit::record($user, 'se.ecart.escalader', 'performance_variance', (string) $variance->id, null, ['reference' => $variance->reference]);

            return $variance->fresh();
        });
    }

    /**
     * @param  list<string>  $interpretations
     * @param  list<string>  $causes
     */
    public function explain(User $user, PerformanceVariance $variance, string $text, array $interpretations, array $causes): PerformanceVariance
    {
        return TransitionLock::run($variance, function (PerformanceVariance $variance) use ($user, $text, $interpretations, $causes) {
            $activity = $this->activity($user, $variance);
            if ($activity->responsible_user_id !== null && $activity->responsible_user_id !== $user->id && ! $user->holds(...FollowUpService::STEERING_ROLES)) {
                throw ValidationException::withMessages(['action' => 'L’explication de l’écart revient au responsable de l’activité.']);
            }
            if ($causes === []) {
                throw ValidationException::withMessages(['causes' => 'Indiquez au moins une catégorie de cause.']);
            }
            $known = SeReferential::query()->where('kind', 'cause')->whereIn('code', $causes)->pluck('code')->all();
            if (count($known) !== count(array_unique($causes))) {
                throw ValidationException::withMessages(['causes' => 'Catégorie de cause inconnue.']);
            }
            $before = $variance->only(['explanation', 'cause_categories']);
            $variance->forceFill([
                'explanation' => $text,
                'explained_by' => $user->id,
                'explanation_received_at' => now(),
                'explanation_requested_at' => $variance->explanation_requested_at ?? now(),
                'interpretations' => array_values($interpretations),
                'cause_categories' => array_values(array_unique($causes)),
                'cause_category' => $causes[0],
                'cause' => $text,
            ])->save();
            FinancialAudit::record($user, 'se.ecart.expliquer', 'performance_variance', (string) $variance->id, $before, ['causes' => $causes, 'interpretations' => $interpretations]);

            return $variance->fresh();
        });
    }

    /**
     * Ouvre un problème (événement survenu) ; un risque lié est marqué
     * « survenu » et rattaché au problème.
     *
     * @param  array{nature: string, impact?: string|null, occurred_on: string, se_risk_id?: int|null}  $data
     */
    public function openProblem(User $user, PerformanceVariance $variance, array $data): SeProblem
    {
        return DB::transaction(function () use ($user, $variance, $data) {
            $activity = $this->activity($user, $variance);
            $risk = null;
            if (! empty($data['se_risk_id'])) {
                $risk = SeRisk::query()->whereKey($data['se_risk_id'])->where('pap_enrichment_id', $activity->id)->first();
                if ($risk === null) {
                    throw ValidationException::withMessages(['se_risk_id' => 'Ce risque ne concerne pas l’activité.']);
                }
                $risk->forceFill(['status' => 'survenu', 'last_comment' => 'Converti en problème', 'reviewed_on' => today()])->save();
            }
            $problem = SeProblem::query()->create([
                'reference' => $this->nextReference('PB'),
                'pap_enrichment_id' => $activity->id,
                'performance_variance_id' => $variance->id,
                'se_risk_id' => $risk?->id,
                'nature' => $data['nature'],
                'impact' => $data['impact'] ?? null,
                'occurred_on' => $data['occurred_on'],
                'status' => 'ouvert',
                'created_by' => $user->id,
            ]);
            FinancialAudit::record($user, 'se.probleme.ouvrir', 'se_problem', (string) $problem->id, null, ['reference' => $problem->reference, 'risque' => $risk?->reference]);

            return $problem;
        });
    }

    /**
     * Action corrective rattachée à l’écart (et au problème s’il existe).
     *
     * @param  array{anomaly: string, cause: string, description: string, responsible_label: string, responsible_role?: string|null, decided_on: string, due_on: string, expected_result?: string|null}  $data
     */
    public function correctiveAction(User $user, PerformanceVariance $variance, array $data): CorrectiveAction
    {
        $activity = $this->activity($user, $variance);
        $problemId = SeProblem::query()->where('performance_variance_id', $variance->id)->latest('id')->value('id');
        $action = $this->monitoring->corrective($user, [
            'performance_variance_id' => $variance->id,
            'pap_enrichment_id' => $activity->id,
            'description' => $data['description'],
            'responsible_role' => $data['responsible_role'] ?? 'directeur',
            'decided_on' => $data['decided_on'],
            'due_on' => $data['due_on'],
            'expected_result' => $data['expected_result'] ?? null,
        ]);
        $action->forceFill([
            'reference' => $this->nextReference('AC'),
            'anomaly' => $data['anomaly'],
            'cause' => $data['cause'],
            'responsible_label' => $data['responsible_label'],
            'se_problem_id' => $problemId,
        ])->save();

        return $action->fresh();
    }

    private function activity(User $user, PerformanceVariance $variance): PapEnrichment
    {
        $activity = PapEnrichment::query()->with(['budgetLine', 'responsible'])->findOrFail($variance->pap_enrichment_id);
        $this->monitoring->assertVisible($user, $activity);

        return $activity;
    }

    /**
     * Notifications réellement envoyées pour cet écart (table notifications).
     *
     * @return list<array{le: string|null, message: string, destinataire: string|null}>
     */
    private function notifications(PerformanceVariance $variance): array
    {
        $link = '/suivi/ecarts/'.$variance->id;

        return DB::table('notifications')
            ->where('type', MonitoringAlert::class)
            ->where('created_at', '>=', $variance->created_at?->copy()->startOfDay())
            ->orderBy('created_at')
            ->get(['data', 'notifiable_id', 'created_at'])
            ->map(fn ($row) => ['data' => json_decode($row->data, true) ?: [], 'user' => $row->notifiable_id, 'le' => $row->created_at])
            ->filter(fn (array $row) => ($row['data']['lien'] ?? null) === $link)
            ->map(function (array $row) {
                $user = User::query()->find($row['user'], ['id', 'name', 'role']);

                return [
                    'le' => $row['le'] ? substr((string) $row['le'], 0, 10) : null,
                    'message' => (string) ($row['data']['message'] ?? ''),
                    'destinataire' => $user ? $user->name.' · '.$user->role : null,
                ];
            })
            ->values()
            ->all();
    }

    private function rate(int|float $part, int|float $total): float
    {
        return $total > 0 ? round($part / $total * 100, 2) : 0.0;
    }

    private function notifyResponsible(PapEnrichment $activity, string $message, PerformanceVariance $variance): void
    {
        $targets = $activity->responsible
            ? collect([$activity->responsible])
            : app(TaskAudience::class)->forRole('directeur', $activity->budgetLine?->organization_unit_id);
        $targets->each(fn (User $target) => $target->notify(new MonitoringAlert($message, '/suivi/ecarts/'.$variance->id)));
    }

    private function nextReference(string $prefix): string
    {
        return SeReference::next($prefix);
    }
}
