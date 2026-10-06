<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\SeProof;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Notifications\MonitoringAlert;
use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Notifications\RoleHolders;
use App\Shared\Support\TransitionLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Suivi dans le temps des mesures correctives, risques et recommandations
 * (description S&E §33, §35, §42-44, §67). Chaque mise à jour est verrouillée
 * et journalisée avec ses valeurs avant/après ; une clôture exige une preuve.
 */
class FollowUpService
{
    /**
     * Rôles de pilotage autorisés à mettre à jour un élément dont ils ne sont
     * pas responsables.
     *
     * @var list<string>
     */
    public const STEERING_ROLES = ['directeur', 'directeur_budget', 'controleur_financier', 'secretaire_general', 'administrateur_fonctionnel'];

    public function __construct(private readonly MonitoringService $monitoring) {}

    /**
     * @param  array{status?: string|null, progress?: int|null, comment?: string|null}  $data
     */
    public function updateCorrective(User $user, CorrectiveAction $action, array $data): CorrectiveAction
    {
        return TransitionLock::run($action, function (CorrectiveAction $action) use ($user, $data) {
            $this->assertCanUpdate($user, $action->responsible_role, $action->pap_enrichment_id);
            if (in_array($action->status, CorrectiveAction::FINAL, true)) {
                throw ValidationException::withMessages(['status' => 'Cette mesure corrective est close.']);
            }
            $status = $data['status'] ?? $action->status;
            $progress = array_key_exists('progress', $data) && $data['progress'] !== null ? (int) $data['progress'] : (int) $action->progress;
            if ($status === 'abandonnee' && blank($data['comment'] ?? null)) {
                throw ValidationException::withMessages(['comment' => 'L’abandon d’une mesure corrective doit être motivé.']);
            }
            if ($status === 'realisee') {
                $progress = 100;
            }
            if ($status === 'cloturee') {
                $this->assertProof($action, 'La clôture d’une mesure corrective exige une preuve.');
                $progress = 100;
            }

            return $this->apply($user, $action, 'se.mesure_corrective.suivi', 'corrective_action', [
                'status' => $status,
                'progress' => $progress,
                'last_comment' => $data['comment'] ?? $action->last_comment,
                'closed_at' => in_array($status, CorrectiveAction::FINAL, true) ? now() : null,
            ], $data['comment'] ?? null);
        });
    }

    /**
     * Revue d’un risque : réévaluation de la probabilité et de l’impact,
     * traitement et statut. La criticité reste calculée.
     *
     * @param  array{probability?: int|null, impact?: int|null, status?: string|null, prevention?: string|null, mitigation?: string|null, comment?: string|null}  $data
     */
    public function reviewRisk(User $user, SeRisk $risk, array $data): SeRisk
    {
        return TransitionLock::run($risk, function (SeRisk $risk) use ($user, $data) {
            $this->assertCanUpdate($user, $risk->responsible_role, $risk->pap_enrichment_id);
            if ($risk->status === 'clos') {
                throw ValidationException::withMessages(['status' => 'Ce risque est clos.']);
            }
            $status = $data['status'] ?? $risk->status;
            if (in_array($status, ['clos', 'survenu'], true) && blank($data['comment'] ?? null)) {
                throw ValidationException::withMessages(['comment' => 'La clôture ou la survenance d’un risque doit être commentée.']);
            }
            $wasCritical = $risk->criticite() === 'critique';
            $row = $this->apply($user, $risk, 'se.risque.revue', 'se_risk', [
                'probability' => $data['probability'] ?? $risk->probability,
                'impact' => $data['impact'] ?? $risk->impact,
                'prevention' => $data['prevention'] ?? $risk->prevention,
                'mitigation' => $data['mitigation'] ?? $risk->mitigation,
                'status' => $status,
                'reviewed_on' => today(),
                'last_comment' => $data['comment'] ?? $risk->last_comment,
                'closed_at' => $status === 'clos' ? now() : null,
            ], $data['comment'] ?? null);
            if (! $wasCritical && $row->criticite() === 'critique') {
                $this->notifyRole($row->responsible_role, 'Risque devenu critique '.$row->reference, '/suivi/activites/'.$row->pap_enrichment_id);
            }

            return $row;
        });
    }

    /**
     * @param  array{status?: string|null, progress?: int|null, comment?: string|null}  $data
     */
    public function updateRecommendation(User $user, SeRecommendation $recommendation, array $data): SeRecommendation
    {
        return TransitionLock::run($recommendation, function (SeRecommendation $recommendation) use ($user, $data) {
            $this->assertCanUpdate($user, $recommendation->responsible_role, $recommendation->pap_enrichment_id);
            if ($recommendation->status === 'cloturee') {
                throw ValidationException::withMessages(['status' => 'Cette recommandation est clôturée.']);
            }
            $status = $data['status'] ?? $recommendation->status;
            $progress = array_key_exists('progress', $data) && $data['progress'] !== null ? (int) $data['progress'] : (int) $recommendation->progress;
            if ($status === 'rejetee' && blank($data['comment'] ?? null)) {
                throw ValidationException::withMessages(['comment' => 'Le rejet d’une recommandation doit être motivé.']);
            }
            if (in_array($status, ['realisee', 'cloturee'], true)) {
                $this->assertProof($recommendation, 'Une recommandation ne se déclare réalisée ou clôturée qu’avec une preuve de mise en œuvre.');
                $progress = 100;
            }

            return $this->apply($user, $recommendation, 'se.recommandation.suivi', 'se_recommendation', [
                'status' => $status,
                'progress' => $progress,
                'last_comment' => $data['comment'] ?? $recommendation->last_comment,
                'closed_at' => in_array($status, SeRecommendation::FINAL, true) ? now() : null,
            ], $data['comment'] ?? null);
        });
    }

    /**
     * Relances automatiques (description S&E §66-68) : chaque mesure corrective
     * ou recommandation échue est signalée une fois par jour à son
     * responsable et à la hiérarchie.
     *
     * @return array{mesures: int, recommandations: int}
     */
    public function remindOverdue(): array
    {
        $counts = ['mesures' => 0, 'recommandations' => 0];
        $due = fn ($query) => $query->whereDate('due_on', '<', today())
            ->where(fn ($inner) => $inner->whereNull('last_reminded_at')->orWhereDate('last_reminded_at', '<', today()));

        CorrectiveAction::query()->whereNotIn('status', [...CorrectiveAction::FINAL, 'realisee'])->where($due)->get()
            ->each(function (CorrectiveAction $action) use (&$counts): void {
                $this->notifyRole($action->responsible_role, 'Mesure corrective en retard depuis le '.$action->due_on?->format('d/m/Y').' : '.str($action->description)->limit(80), '/suivi/ecarts', escalate: true);
                $action->forceFill(['last_reminded_at' => now()])->save();
                $counts['mesures']++;
            });

        SeRecommendation::query()->whereNotIn('status', SeRecommendation::FINAL)->where($due)->get()
            ->each(function (SeRecommendation $recommendation) use (&$counts): void {
                $this->notifyRole($recommendation->responsible_role, 'Recommandation '.$recommendation->reference.' échue depuis le '.$recommendation->due_on?->format('d/m/Y'), '/suivi/synthese', escalate: true);
                $recommendation->forceFill(['last_reminded_at' => now()])->save();
                $counts['recommandations']++;
            });

        return $counts;
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $changes
     * @return TModel
     */
    private function apply(User $user, Model $model, string $action, string $type, array $changes, ?string $motif): Model
    {
        $before = $model->only(array_keys($changes));
        $model->forceFill($changes)->save();
        $after = $model->only(array_keys($changes));
        FinancialAudit::record($user, $action, $type, (string) $model->getKey(), $this->scalar($before), $this->scalar($after), $motif);

        return $model->fresh();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scalar(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : $value, $values);
    }

    private function assertCanUpdate(User $user, ?string $responsibleRole, ?int $activityId): void
    {
        if ($activityId !== null) {
            $this->monitoring->assertVisible($user, PapEnrichment::query()->findOrFail($activityId));
        }
        $same = $responsibleRole === null ? $user->role === null : $user->holds($responsibleRole);
        if (! $same && ! $user->holds(...self::STEERING_ROLES)) {
            throw ValidationException::withMessages(['action' => 'Seul le responsable désigné ou un pilote peut mettre à jour cet élément.']);
        }
    }

    private function assertProof(Model $model, string $message): void
    {
        if (! SeProof::query()->where('proofable_type', $model->getMorphClass())->where('proofable_id', $model->getKey())->exists()) {
            throw ValidationException::withMessages(['preuve' => $message]);
        }
    }

    private function notifyRole(?string $role, string $message, string $lien, bool $escalate = false): void
    {
        $roles = array_filter([$role, $escalate ? 'directeur' : null]);
        app(RoleHolders::class)->query(array_values($roles))->get()
            ->each(fn (User $target) => $target->notify(new MonitoringAlert($message, $lien)));
    }
}
