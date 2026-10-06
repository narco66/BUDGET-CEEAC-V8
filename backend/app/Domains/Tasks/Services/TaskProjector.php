<?php

namespace App\Domains\Tasks\Services;

use App\Domains\Budget\Models\BudgetDossier;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenueReceipt;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Notifications\TaskAssigned;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaskProjector
{
    public function sync(?string $entityType = null, ?int $entityId = null): void
    {
        $expected = $this->expected($entityType, $entityId);
        $fingerprints = [];

        foreach ($expected as $row) {
            $fingerprints[] = $row['fingerprint'];
            $this->upsert($row);
        }

        $stale = WorkflowTask::query()->where('status', '!=', 'terminee');
        if ($entityType !== null && $entityId !== null) {
            $stale->where('entity_type', $entityType)->where('entity_id', $entityId);
        }

        $stale
            ->whereNotIn('fingerprint', $fingerprints === [] ? ['__none__'] : $fingerprints)
            ->orderBy('id')
            ->each(function (WorkflowTask $task) {
                $task->forceFill([
                    'status' => 'terminee',
                    'completed_at' => $task->completed_at ?? now(),
                    'completion_action' => $this->completionOf($task),
                ])->save();
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expected(?string $entityType, ?int $entityId): array
    {
        return match ($entityType) {
            'expression_besoin' => $this->needs($entityId),
            'engagement' => $this->engagements($entityId),
            'liquidation' => $this->liquidations($entityId),
            'ordonnancement' => $this->ordonnancements($entityId),
            'paiement' => $this->paiements($entityId),
            'indicator_measurement', 'physical_achievement', 'corrective_action', 'se_recommendation', 'performance_report' => $this->monitoring($entityType, $entityId),
            'revenue_order' => $this->revenueOrders($entityId),
            'revenue_receipt' => $this->revenueReceipts($entityId),
            'revenue_forecast' => $this->revenueForecasts($entityId),
            'budget_dossier' => $this->dossiersBudget($entityId),
            null => [
                ...$this->needs(),
                ...$this->engagements(),
                ...$this->liquidations(),
                ...$this->ordonnancements(),
                ...$this->paiements(),
                ...$this->monitoring(),
                ...$this->revenueOrders(),
                ...$this->revenueReceipts(),
                ...$this->revenueForecasts(),
                ...$this->dossiersBudget(),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsert(array $row): void
    {
        $existing = WorkflowTask::query()
            ->where('fingerprint', $row['fingerprint'])
            ->where('status', '!=', 'terminee')
            ->first();

        if ($existing) {
            $status = $existing->status === 'en_cours' ? 'en_cours' : $row['status'];
            $existing->fill(collect($row)->except(['fingerprint', 'status', 'assigned_at', 'reference'])->all());
            $existing->status = $status;
            $existing->save();

            return;
        }

        $task = WorkflowTask::query()->create([
            ...$row,
            'reference' => 'TMP-'.Str::uuid(),
            'assigned_at' => now(),
        ]);
        $task->forceFill([
            'reference' => sprintf('TSK-%d-%06d', now()->year, $task->id),
        ])->save();
        $this->notifyAssignees($task);
    }

    /**
     * Destinataires : l’utilisateur affecté, sinon les titulaires du rôle
     * (directeur et commissaire selon la structure compétente).
     *
     * @return Collection<int, User>
     */
    public function recipients(WorkflowTask $task): Collection
    {
        return app(TaskAudience::class)->recipients($task);
    }

    /**
     * La tâche est le seul avis « action attendue » : les circuits ne
     * doublent plus ce message. Un retour porte son motif.
     */
    private function notifyAssignees(WorkflowTask $task): void
    {
        $motif = $task->status === 'retournee' ? $this->returnMotif($task) : null;
        $message = TaskWording::assigned($task, $motif);
        $lien = '/taches/'.$task->id;

        // L’auteur de la transition sait déjà ce qui l’attend : on ne le notifie pas.
        $auteur = auth()->id();
        $this->recipients($task)->reject(fn (User $user) => $auteur !== null && (int) $user->id === (int) $auteur)->each(function (User $user) use ($message, $lien) {
            $user->notify((new TaskAssigned($message, $lien))->afterCommit());
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function needs(?int $onlyId = null): array
    {
        $closed = ['approuvee', 'rejetee', 'annulee', 'transformee_engagement'];

        return ExpressionBesoin::query()
            ->with(['initiator', 'organizationUnit.parent', 'exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->whereNotIn('status', $closed)
            ->get()
            ->map(function (ExpressionBesoin $eb) {
                $returned = in_array($eb->status->value, ['retournee', 'a_corriger', 'brouillon', 'a_completer'], true);
                $role = $returned ? 'initiateur' : (string) $eb->workflow_step;
                $action = match (true) {
                    in_array($eb->status->value, ['retournee', 'a_corriger'], true) => 'corriger',
                    in_array($eb->status->value, ['brouillon', 'a_completer'], true) => 'completer',
                    $eb->workflow_step === 'ordonnateur' => 'approuver',
                    default => 'valider',
                };

                return $this->row(
                    'eb',
                    'expression_besoin',
                    $eb->id,
                    $eb->reference,
                    $action,
                    $role,
                    $returned ? $eb->initiator_id : null,
                    $eb->organization_unit_id,
                    (int) $eb->montant,
                    $eb->objet,
                    $eb->initiator?->name,
                    $eb->organizationUnit?->structureLabel(),
                    (string) $eb->workflow_step,
                    '/expressions-besoin/'.$eb->id,
                    $eb->due_on,
                    $returned && ! in_array($eb->status->value, ['brouillon', 'a_completer'], true),
                    $eb->exercice?->annee,
                    $eb->initiator_id,
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function engagements(?int $onlyId = null): array
    {
        return Engagement::query()
            ->with(['expressionBesoin.initiator', 'expressionBesoin.organizationUnit.parent', 'expressionBesoin.exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->whereNotIn('status', ['vise', 'transforme_liquidation', 'rejete', 'annule'])
            ->get()
            ->map(function (Engagement $engagement) {
                $eb = $engagement->expressionBesoin;
                $returned = $engagement->status->value === 'retourne';
                $action = $engagement->workflow_step === 'controleur_financier' ? 'viser' : ($returned ? 'corriger' : 'valider');

                // Un engagement retourné revient à l’Expert Budget (workflow_step),
                // pas à l’initiateur, qui n’a aucune action sur l’engagement.
                return $this->row(
                    'engagement',
                    'engagement',
                    $engagement->id,
                    $engagement->reference,
                    $action,
                    (string) $engagement->workflow_step,
                    null,
                    $eb?->organization_unit_id,
                    (int) $engagement->montant,
                    $eb?->objet,
                    $eb?->initiator?->name,
                    $eb?->organizationUnit?->structureLabel(),
                    (string) $engagement->workflow_step,
                    '/engagements/'.$engagement->id,
                    $engagement->due_on,
                    $returned,
                    $eb?->exercice?->annee,
                    $eb?->initiator_id,
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liquidations(?int $onlyId = null): array
    {
        return Liquidation::query()
            ->with(['engagement.expressionBesoin.initiator', 'engagement.expressionBesoin.organizationUnit.parent', 'engagement.expressionBesoin.exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->whereNotIn('status', ['visee', 'rejetee', 'annulee', 'transformee_ordonnancement'])
            ->get()
            ->map(function (Liquidation $liquidation) {
                $eb = $liquidation->engagement?->expressionBesoin;
                $control = $liquidation->workflow_step === 'controleur_financier';
                $returned = in_array($liquidation->status->value, ['retournee', 'complement'], true);

                return $this->row(
                    'liquidation',
                    'liquidation',
                    $liquidation->id,
                    $liquidation->reference,
                    $control ? 'viser' : 'certifier',
                    $control ? 'controleur_financier' : 'initiateur',
                    $control ? null : $eb?->initiator_id,
                    $eb?->organization_unit_id,
                    (int) $liquidation->montant_net,
                    $eb?->objet,
                    $eb?->initiator?->name,
                    $eb?->organizationUnit?->structureLabel(),
                    (string) $liquidation->workflow_step,
                    '/liquidations/'.$liquidation->id,
                    $liquidation->due_on,
                    $returned,
                    $eb?->exercice?->annee,
                    $eb?->initiator_id,
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ordonnancements(?int $onlyId = null): array
    {
        return Ordonnancement::query()
            ->with(['liquidation.engagement.expressionBesoin.initiator', 'liquidation.engagement.expressionBesoin.organizationUnit.parent', 'liquidation.engagement.expressionBesoin.exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            // Un ordre retourné rouvre la liquidation : la tâche de correction
            // est portée par la liquidation, sans doublon sur l’ordre.
            ->whereIn('status', ['a_signer', 'transmission_erreur'])
            ->get()
            ->map(function (Ordonnancement $ordre) {
                $eb = $ordre->liquidation?->engagement?->expressionBesoin;
                $returned = $ordre->status->value === 'retourne';
                $action = match ($ordre->status->value) {
                    'retourne' => 'corriger',
                    'transmission_erreur' => 'reprendre',
                    default => 'signer',
                };

                return $this->row(
                    'ordonnancement',
                    'ordonnancement',
                    $ordre->id,
                    $ordre->reference,
                    $action,
                    $returned ? 'initiateur' : (string) $ordre->ordonnateur_role,
                    $returned ? $eb?->initiator_id : null,
                    $eb?->organization_unit_id,
                    (int) $ordre->montant,
                    $eb?->objet,
                    $eb?->initiator?->name,
                    $eb?->organizationUnit?->structureLabel(),
                    (string) $ordre->workflow_step,
                    '/ordonnancements/'.$ordre->id,
                    $ordre->due_on,
                    $returned,
                    $eb?->exercice?->annee,
                    $eb?->initiator_id,
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paiements(?int $onlyId = null): array
    {
        return Paiement::query()
            ->with(['ordonnancement.liquidation.engagement.expressionBesoin.initiator', 'ordonnancement.liquidation.engagement.expressionBesoin.organizationUnit.parent', 'ordonnancement.liquidation.engagement.expressionBesoin.exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->whereNotIn('status', ['cloture', 'rejete'])
            ->get()
            ->map(function (Paiement $paiement) {
                $eb = $paiement->ordonnancement?->liquidation?->engagement?->expressionBesoin;
                [$role, $action] = match ($paiement->status->value) {
                    'a_controler' => ['chef_comptable', 'valider'],
                    'a_signer', 'suspendu' => ['agent_comptable', 'signer'],
                    'autorise', 'paye_partiel' => ['comptable', 'executer'],
                    'a_rapprocher' => ['comptable', 'rapprocher'],
                    'rejete_bancaire' => ['comptable', 'reprendre'],
                    'genere' => ['comptable', 'prendre_en_charge'],
                    default => ['comptable', 'preparer'],
                };

                return $this->row(
                    'paiement',
                    'paiement',
                    $paiement->id,
                    $paiement->reference,
                    $action,
                    $role,
                    null,
                    $eb?->organization_unit_id,
                    (int) $paiement->montant,
                    $eb?->objet,
                    $paiement->titulaire ?: $eb?->initiator?->name,
                    $eb?->organizationUnit?->structureLabel(),
                    (string) $paiement->workflow_step,
                    '/paiements/'.$paiement->id,
                    $paiement->due_on,
                    $paiement->status->value === 'retourne',
                    $eb?->exercice?->annee,
                    $eb?->initiator_id,
                );
            })
            ->all();
    }

    /**
     * « Mes tâches Suivi-Évaluation » (description S&E §54) : saisies à
     * compléter ou corriger, données à valider, mesures correctives et
     * recommandations échues, rapports en revue.
     *
     * @return list<array<string, mixed>>
     */
    private function monitoring(?string $onlyType = null, ?int $onlyId = null): array
    {
        $include = fn (string $type): bool => $onlyType === null || $onlyType === $type;

        $measurements = $include('indicator_measurement') ? IndicatorMeasurement::query()
            ->with('indicator.activity.budgetLine.organizationUnit', 'indicator.activity.budgetLine.exercice')
            ->whereIn('status', ['brouillon', 'soumis', 'a_corriger', 'valide_responsable', 'valide'])
            ->whereNull('superseded_at')
            ->when($onlyId !== null && $onlyType === 'indicator_measurement', fn ($query) => $query->whereKey($onlyId))
            ->get()
            ->map(function (IndicatorMeasurement $measurement) {
                $activity = $measurement->indicator?->activity;
                [$action, $role, $userId] = $this->seStep($measurement->status, $measurement->author_id, $activity?->responsible_user_id);

                return $this->row(
                    'se',
                    'indicator_measurement',
                    $measurement->id,
                    'MES-'.$measurement->id,
                    $action,
                    $role,
                    $userId,
                    $activity?->budgetLine?->organization_unit_id,
                    0,
                    $measurement->indicator?->label,
                    null,
                    $activity?->budgetLine?->organizationUnit?->sigle,
                    $measurement->status,
                    $measurement->indicator_id ? '/suivi/indicateurs/'.$measurement->indicator_id.'/saisie' : '/suivi/saisie',
                    null,
                    $measurement->status === 'a_corriger',
                    $activity?->budgetLine?->exercice?->annee,
                    $measurement->author_id,
                );
            }) : collect();

        $achievements = $include('physical_achievement') ? PhysicalAchievement::query()
            ->with('activity.budgetLine.organizationUnit', 'activity.budgetLine.exercice')
            ->whereIn('status', ['brouillon', 'soumis', 'a_corriger', 'valide_responsable', 'valide'])
            ->whereNull('superseded_at')
            ->when($onlyId !== null && $onlyType === 'physical_achievement', fn ($query) => $query->whereKey($onlyId))
            ->get()
            ->map(function (PhysicalAchievement $achievement) {
                [$action, $role, $userId] = $this->seStep($achievement->status, $achievement->author_id, $achievement->activity?->responsible_user_id);

                return $this->row(
                    'se',
                    'physical_achievement',
                    $achievement->id,
                    'REA-'.$achievement->id,
                    $action,
                    $role,
                    $userId,
                    $achievement->activity?->budgetLine?->organization_unit_id,
                    0,
                    'Réalisation · '.$achievement->activity?->activite,
                    null,
                    $achievement->activity?->budgetLine?->organizationUnit?->sigle,
                    $achievement->status,
                    $achievement->pap_enrichment_id ? '/suivi/activites/'.$achievement->pap_enrichment_id : '/suivi/saisie',
                    null,
                    $achievement->status === 'a_corriger',
                    $achievement->activity?->budgetLine?->exercice?->annee,
                    $achievement->author_id,
                );
            }) : collect();

        $correctives = $include('corrective_action') ? CorrectiveAction::query()
            ->whereNotIn('status', [...CorrectiveAction::FINAL, 'realisee'])
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', today()->addDays(7))
            ->when($onlyId !== null && $onlyType === 'corrective_action', fn ($query) => $query->whereKey($onlyId))
            ->get()
            ->map(fn (CorrectiveAction $action) => $this->row(
                'se',
                'corrective_action',
                $action->id,
                'MC-'.$action->id,
                'mettre_a_jour',
                (string) $action->responsible_role,
                null,
                null,
                0,
                $action->description,
                null,
                null,
                $action->status,
                $action->performance_variance_id ? '/suivi/ecarts/'.$action->performance_variance_id : '/suivi/actions',
                $action->due_on,
                false,
            )) : collect();

        $recommendations = $include('se_recommendation') ? SeRecommendation::query()
            ->whereNotIn('status', SeRecommendation::FINAL)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', today()->addDays(7))
            ->when($onlyId !== null && $onlyType === 'se_recommendation', fn ($query) => $query->whereKey($onlyId))
            ->get()
            ->map(fn (SeRecommendation $recommendation) => $this->row(
                'se',
                'se_recommendation',
                $recommendation->id,
                $recommendation->reference,
                'mettre_a_jour',
                (string) $recommendation->responsible_role,
                null,
                null,
                0,
                $recommendation->description,
                null,
                null,
                $recommendation->status,
                '/suivi/actions',
                $recommendation->due_on,
                false,
            )) : collect();

        $reports = $include('performance_report') ? PerformanceReport::query()
            ->whereIn('status', ['en_revue', 'valide'])
            ->when($onlyId !== null && $onlyType === 'performance_report', fn ($query) => $query->whereKey($onlyId))
            ->get()
            ->map(fn (PerformanceReport $report) => $this->row(
                'se',
                'performance_report',
                $report->id,
                $report->reference.'-v'.$report->version,
                $report->status === 'en_revue' ? 'valider' : 'publier',
                'directeur_budget',
                null,
                null,
                0,
                $report->title,
                null,
                null,
                $report->status,
                '/suivi/rapports',
                null,
                false,
            )) : collect();

        return [...$measurements, ...$achievements, ...$correctives, ...$recommendations, ...$reports];
    }

    /**
     * Étape attendue d’une saisie S&E selon le circuit de la maquette :
     * saisie (auteur), validation responsable (responsable d’activité ou,
     * à défaut, la direction), validation hiérarchique (direction),
     * consolidation (responsable S&E).
     *
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function seStep(string $status, ?int $authorId, ?int $responsibleId): array
    {
        return match ($status) {
            'soumis' => ['valider', $responsibleId !== null ? 'responsable_activite' : 'directeur', $responsibleId],
            'valide_responsable' => ['valider', 'directeur', null],
            // Responsable S&E s’il existe un titulaire, sinon le Directeur du Budget,
            // qui détient aussi le droit de consolider : la tâche n’est jamais orpheline.
            'valide' => ['consolider', $this->consolidateur(), null],
            'a_corriger' => ['corriger', 'initiateur', $authorId],
            default => ['saisir', 'initiateur', $authorId],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenueForecasts(?int $onlyId = null): array
    {
        return RevenueForecast::query()
            ->with(['author', 'exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->where('statut', 'soumis')
            ->get()
            ->map(fn (RevenueForecast $forecast) => $this->row(
                'recette',
                'revenue_forecast',
                $forecast->id,
                $forecast->code,
                'valider',
                'directeur_budget',
                null,
                $forecast->organization_unit_id,
                (int) $forecast->montant,
                $forecast->label,
                $forecast->author?->name,
                null,
                $forecast->statut,
                '/recettes/previsions/'.$forecast->id,
                $forecast->date_prevue,
                false,
                $forecast->exercice?->annee,
                $forecast->author_id,
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenueOrders(?int $onlyId = null): array
    {
        return RevenueOrder::query()
            ->with(['author', 'exercice'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->whereIn('statut', ['soumis', 'verifie', 'valide'])
            ->get()
            ->map(function (RevenueOrder $order) {
                [$action, $role] = match ($order->statut) {
                    'soumis' => ['verifier', 'expert_budget'],
                    'verifie' => ['valider', 'directeur_budget'],
                    default => ['prendre_en_charge', 'comptable'],
                };

                return $this->row(
                    'recette',
                    'revenue_order',
                    $order->id,
                    $order->reference,
                    $action,
                    $role,
                    null,
                    $order->organization_unit_id,
                    (int) $order->montant,
                    $order->motif,
                    $order->author?->name,
                    null,
                    $order->statut,
                    '/recettes/titres/'.$order->id,
                    $order->echeance,
                    false,
                    $order->exercice?->annee,
                    $order->created_by,
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenueReceipts(?int $onlyId = null): array
    {
        return RevenueReceipt::query()
            ->with('author')
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->where('statut', 'non_rapproche')
            ->get()
            ->map(fn (RevenueReceipt $receipt) => $this->row(
                'recette',
                'revenue_receipt',
                $receipt->id,
                $receipt->reference,
                'rapprocher',
                'chef_comptable',
                null,
                null,
                (int) $receipt->montant,
                $receipt->commentaire,
                $receipt->author?->name,
                null,
                $receipt->statut,
                '/recettes/rapprochements',
                $receipt->recu_le,
                false,
                $receipt->recu_le?->year,
                $receipt->created_by,
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function dossiersBudget(?int $onlyId = null): array
    {
        return BudgetDossier::query()
            ->with(['author', 'organizationUnit', 'campaign.exercice', 'lines'])
            ->when($onlyId !== null, fn ($query) => $query->whereKey($onlyId))
            ->whereIn('statut', ['soumis', 'retourne'])
            ->get()
            ->map(function (BudgetDossier $dossier): array {
                $retour = $dossier->statut === 'retourne';

                return $this->row(
                    'preparation',
                    'budget_dossier',
                    $dossier->id,
                    $dossier->reference,
                    $retour ? 'corriger' : 'valider',
                    $retour ? 'expert_budget' : 'directeur_budget',
                    $retour ? $dossier->author_id : null,
                    $dossier->organization_unit_id,
                    (int) $dossier->lines->sum('montant'),
                    $dossier->titre,
                    $dossier->author?->name,
                    $dossier->organizationUnit?->sigle,
                    $dossier->statut,
                    '/preparation/dossiers/'.$dossier->id,
                    $dossier->campaign?->date_cloture,
                    $retour,
                    $dossier->campaign?->exercice?->annee,
                    $retour ? null : $dossier->author_id,
                );
            })
            ->all();
    }

    private function row(
        string $module,
        string $entityType,
        int $entityId,
        string $reference,
        string $action,
        string $role,
        ?int $userId,
        ?int $organizationId,
        int $amount,
        ?string $objet,
        ?string $demandeur,
        ?string $structure,
        string $step,
        string $lien,
        mixed $dueOn,
        bool $returned,
        mixed $exercice = null,
        ?int $initiatorId = null,
    ): array {
        if ($userId !== null && $userId === $initiatorId && in_array($action, ['valider', 'viser', 'approuver', 'signer'], true)) {
            $userId = null;
            if (in_array($role, ['responsable_activite', 'initiateur'], true)) {
                $role = 'directeur';
            }
        }

        $due = $dueOn instanceof Carbon ? $dueOn : ($dueOn ? Carbon::parse($dueOn) : null);
        $late = $due !== null && $due->lt(today());
        $soon = (int) config('gesbudep.taches.relance_avant_jours', 2);
        $distant = (int) config('gesbudep.taches.priorite_faible_apres_jours', 14);
        $priority = match (true) {
            $late => 'critique',
            $returned || ($due !== null && $due->lte(today()->addDays($soon))) => 'haute',
            $due !== null && $due->gt(today()->addDays($distant)) => 'faible',
            default => 'normale',
        };

        return [
            'fingerprint' => hash('sha256', implode('|', [$module, $entityId, $role, $action, $step])),
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'dossier_reference' => $reference,
            'subject' => TaskWording::action($action).' '.$reference,
            'action' => $action,
            'step' => $step,
            'assigned_role' => $role,
            'assigned_user_id' => $userId,
            'organization_unit_id' => $organizationId,
            'priority' => $priority,
            'status' => $returned ? 'retournee' : 'a_traiter',
            'amount' => $amount,
            'objet' => $objet,
            'demandeur' => $demandeur,
            'structure' => $structure,
            'exercice_year' => $exercice !== null ? (int) $exercice : null,
            'lien' => $lien,
            'due_on' => $due?->toDateString(),
        ];
    }

    private ?string $consolidateur = null;

    private function consolidateur(): string
    {
        return $this->consolidateur ??= app(RoleHolders::class)->query('responsable_se')->exists()
            ? 'responsable_se'
            : 'directeur_budget';
    }

    private function returnMotif(WorkflowTask $task): ?string
    {
        $motif = match ($task->entity_type) {
            'expression_besoin' => ExpressionBesoin::query()->whereKey($task->entity_id)->value('return_motif'),
            'engagement' => Engagement::query()->whereKey($task->entity_id)->value('return_motif'),
            'liquidation' => Liquidation::query()->whereKey($task->entity_id)->value('return_motif'),
            'paiement' => Paiement::query()->whereKey($task->entity_id)->value('return_motif'),
            'budget_dossier' => BudgetDossier::query()->whereKey($task->entity_id)->value('retour_motif'),
            default => null,
        };

        return filled($motif) ? Str::limit((string) $motif, 160) : null;
    }

    private function completionOf(WorkflowTask $task): string
    {
        $status = match ($task->entity_type) {
            'expression_besoin' => ExpressionBesoin::query()->find($task->entity_id)?->status,
            'engagement' => Engagement::query()->find($task->entity_id)?->status,
            'liquidation' => Liquidation::query()->find($task->entity_id)?->status,
            'ordonnancement' => Ordonnancement::query()->find($task->entity_id)?->status,
            'paiement' => Paiement::query()->find($task->entity_id)?->status,
            'indicator_measurement' => IndicatorMeasurement::query()->find($task->entity_id)?->status,
            'physical_achievement' => PhysicalAchievement::query()->find($task->entity_id)?->status,
            'corrective_action' => CorrectiveAction::query()->find($task->entity_id)?->status,
            'se_recommendation' => SeRecommendation::query()->find($task->entity_id)?->status,
            'performance_report' => PerformanceReport::query()->find($task->entity_id)?->status,
            'budget_dossier' => BudgetDossier::query()->find($task->entity_id)?->statut,
            default => null,
        };
        $value = $status instanceof BackedEnum ? (string) $status->value : (string) $status;

        return match (true) {
            str_contains($value, 'rejet') => 'rejetee',
            str_contains($value, 'annul') => 'annulee',
            default => 'etape_suivante',
        };
    }
}
