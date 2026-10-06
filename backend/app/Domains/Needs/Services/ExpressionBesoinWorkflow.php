<?php

namespace App\Domains\Needs\Services;

use App\Domains\Administration\Services\ChainWorkflowCatalog;
use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Services\EngagementWorkflow;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\EbEvent;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Notifications\EbWorkflowNotification;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Services\WorkflowActorResolver;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\BudgetAvailabilityService;
use App\Shared\Support\NumberingService;
use App\Shared\Support\TransitionLock;
use App\Shared\Support\WorkingCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpressionBesoinWorkflow
{
    /**
     * @return list<string>
     */
    public function steps(ExpressionBesoin $eb): array
    {
        $technicalPap = $eb->nature === BudgetNature::Pap && (bool) $eb->organizationUnit?->is_technical;
        $required = $technicalPap
            ? ['initiateur', 'directeur', 'commissaire', 'ordonnateur']
            : ['initiateur', 'directeur', 'secretaire_general', 'ordonnateur'];
        $published = app(ChainWorkflowCatalog::class)->roles('eb', $required);
        if ($published === null) {
            return $required;
        }

        return array_values(array_filter($published, fn (string $role): bool => in_array($role, $required, true)));
    }

    public function stepLabel(string $step, ?OrganizationUnit $unit): string
    {
        return match ($step) {
            'initiateur' => 'Initiateur',
            'directeur' => 'Directeur'.($this->libelleStructure($step, $unit)),
            'commissaire' => 'Commissaire'.($this->libelleStructure($step, $unit)),
            'secretaire_general' => 'Secrétaire Général',
            'ordonnateur' => 'Ordonnateur',
            default => $step,
        };
    }

    public function nextReference(Exercice $exercice, OrganizationUnit $unit): string
    {
        return app(NumberingService::class)->nextExpressionBesoin($exercice, $unit);
    }

    public function createDraft(User $actor, BudgetLine $line): ExpressionBesoin
    {
        $line->loadMissing('exercice', 'organizationUnit');

        if (! $line->exercice->isOpen()) {
            throw ValidationException::withMessages([
                'budget_line_id' => 'L’exercice de cette ligne budgétaire n’est pas ouvert.',
            ]);
        }
        if ($line->nature === BudgetNature::HorsPap && ! app(WorkflowActorResolver::class)->rattacheAuSigle($actor, 'DSG-DRHMG-SMG')) {
            throw ValidationException::withMessages([
                'budget_line_id' => 'Un besoin hors PAP s’initie par un agent du Service des Moyens généraux (DSG-DRHMG-SMG). Le contrôle des crédits reste obligatoire ensuite.',
            ]);
        }

        return DB::transaction(function () use ($actor, $line) {
            $eb = ExpressionBesoin::query()->create([
                'reference' => $this->nextReference($line->exercice, $line->organizationUnit),
                'exercice_id' => $line->exercice_id,
                'organization_unit_id' => $line->organization_unit_id,
                'initiator_id' => $actor->id,
                'budget_line_id' => $line->id,
                'nature' => $line->nature,
                'status' => EbStatus::Brouillon,
                'workflow_step' => 'initiateur',
                'expected_actor_label' => 'Initiateur',
            ]);

            $eb->imputations()->create([
                'budget_line_id' => $line->id,
                'montant' => 0,
            ]);

            $this->log($eb, $actor, 'creation', null, EbStatus::Brouillon->value, null, null, null);

            return $eb;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>|null  $imputations
     */
    public function syncDetails(ExpressionBesoin $eb, array $attributes, array $lines, ?array $imputations): void
    {
        $this->assertEditable($eb);

        $eb->fill($attributes);
        $total = 0;
        $eb->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $unitPrice = (int) $line['prix_unitaire'];
            $amount = $this->montantLigne($this->quantiteTexte($line['quantite']), $unitPrice);
            $total += $amount;

            $eb->lines()->create([
                'pap_task_id' => $line['pap_task_id'] ?? null,
                'position' => $index + 1,
                'designation' => $line['designation'],
                'description' => $line['description'] ?? null,
                'quantite' => $this->quantiteTexte($line['quantite']),
                'unite' => $line['unite'] ?? 'forfait',
                'prix_unitaire' => $unitPrice,
                'montant' => $amount,
                'beneficiaire' => $line['beneficiaire'] ?? null,
                'lieu' => $line['lieu'] ?? null,
                'periode' => $line['periode'] ?? null,
                'observation' => $line['observation'] ?? null,
            ]);
        }

        $eb->montant = $total;
        $eb->imputations()->delete();

        $rows = $imputations ?: [[
            'budget_line_id' => $eb->budget_line_id,
            'montant' => $total,
        ]];

        foreach ($rows as $row) {
            $eb->imputations()->create([
                'budget_line_id' => $row['budget_line_id'],
                'montant' => (int) $row['montant'],
            ]);
        }

        $eb->save();
    }

    public function submit(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performSubmit($locked, $actor));
    }

    public function validateStep(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performValidateStep($locked, $actor));
    }

    /**
     * @param  list<string>  $fields
     */
    public function returnForCorrection(ExpressionBesoin $eb, User $actor, string $motif, string $observations, array $fields): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performReturnForCorrection($locked, $actor, $motif, $observations, $fields));
    }

    public function reject(ExpressionBesoin $eb, User $actor, string $motif, string $observations): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performReject($locked, $actor, $motif, $observations));
    }

    public function approve(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performApprove($locked, $actor));
    }

    /**
     * Génère l’engagement une seule fois : l’EB est verrouillée et l’existence
     * d’un engagement est relue sous verrou (EB-006).
     */
    public function transform(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performTransform($locked, $actor));
    }

    public function cancel(ExpressionBesoin $eb, User $actor, string $motif): ExpressionBesoin
    {
        return TransitionLock::run($eb, fn (ExpressionBesoin $locked) => $this->performCancel($locked, $actor, $motif));
    }

    private function performSubmit(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        $this->assertInitiator($actor, $eb);
        $this->assertEditable($eb);
        $eb->load('exercice', 'organizationUnit', 'budgetLine.enrichment', 'lines', 'imputations.budgetLine', 'documents');

        if (! $eb->exercice->isOpen()) {
            throw ValidationException::withMessages(['exercice' => 'L’exercice n’est pas ouvert à la dépense.']);
        }

        $errors = [];
        if (blank($eb->objet)) {
            $errors['objet'] = 'L’objet du besoin est obligatoire.';
        }
        if (blank($eb->justification)) {
            $errors['justification'] = 'La justification du besoin est obligatoire.';
        }
        if ($eb->lines->isEmpty() || $eb->montant <= 0) {
            $errors['lignes'] = 'Au moins une sous-ligne chiffrée est obligatoire.';
        }

        $imputed = (int) $eb->imputations->sum('montant');
        if ($imputed !== (int) $eb->montant) {
            $errors['imputations'] = 'Le total des imputations doit être égal au total de l’EB.';
        }

        if ($eb->nature === BudgetNature::Pap && blank($eb->budgetLine->enrichment?->activite)) {
            $errors['activite'] = 'L’activité programmatique validée est obligatoire pour une EB PAP.';
        }

        if ($eb->documents->isEmpty()) {
            $errors['documents'] = 'Au moins une pièce justificative est obligatoire avant soumission.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        TransitionLock::rows(BudgetLine::class, $eb->imputations->pluck('budget_line_id'));
        foreach ($eb->imputations as $imputation) {
            app(BudgetAvailabilityService::class)->assertCanImpute($imputation->budgetLine, (int) $imputation->montant, $eb->id);
        }

        $steps = $this->steps($eb);
        $next = $steps[1];
        $from = $eb->status->value;

        $eb->forceFill([
            'status' => $this->statusForStep($next),
            'workflow_step' => $next,
            'expected_actor_label' => $this->stepLabel($next, $eb->organizationUnit),
            'due_on' => WorkingCalendar::dueIn(5),
            'submitted_at' => $eb->submitted_at ?? now(),
            'return_motif' => null,
        ])->save();

        $this->log($eb, $actor, 'soumission', $from, $eb->status->value, null, null, null);

        return $eb;
    }

    private function performValidateStep(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        $this->assertCurrentActor($actor, $eb);

        if ($eb->workflow_step === 'ordonnateur') {
            return $this->performApprove($eb, $actor);
        }

        $steps = $this->steps($eb);
        $index = array_search($eb->workflow_step, $steps, true);
        $next = $steps[$index + 1] ?? null;

        if ($next === null) {
            return $this->performApprove($eb, $actor);
        }

        $from = $eb->status->value;
        $eb->forceFill([
            'status' => $this->statusForStep($next),
            'workflow_step' => $next,
            'expected_actor_label' => $this->stepLabel($next, $eb->organizationUnit),
            'due_on' => WorkingCalendar::dueIn(5),
        ])->save();

        $this->log($eb, $actor, 'validation', $from, $eb->status->value, null, null, null);

        return $eb;
    }

    /**
     * @param  list<string>  $fields
     */
    private function performReturnForCorrection(ExpressionBesoin $eb, User $actor, string $motif, string $observations, array $fields): ExpressionBesoin
    {
        $this->assertCurrentActor($actor, $eb);
        $from = $eb->status->value;
        $snapshot = $this->snapshot($eb);

        $eb->forceFill([
            'status' => EbStatus::Retournee,
            'workflow_step' => 'initiateur',
            'expected_actor_label' => 'Initiateur · à corriger',
            'due_on' => null,
            'returned_at' => now(),
            'return_motif' => $motif,
        ])->save();

        $this->log($eb, $actor, 'retour', $from, EbStatus::Retournee->value, $motif, $observations, $fields, $snapshot);

        return $eb;
    }

    private function performReject(ExpressionBesoin $eb, User $actor, string $motif, string $observations): ExpressionBesoin
    {
        $this->assertCurrentActor($actor, $eb);
        $from = $eb->status->value;

        $eb->forceFill([
            'status' => EbStatus::Rejetee,
            'workflow_step' => 'clos',
            'expected_actor_label' => '—',
            'due_on' => null,
            'rejected_at' => now(),
            'rejection_motif' => $motif,
        ])->save();

        $this->log($eb, $actor, 'rejet', $from, EbStatus::Rejetee->value, $motif, $observations, null);
        $eb->initiator?->notify(new EbWorkflowNotification($eb, 'rejetée : '.$motif));

        return $eb;
    }

    private function performApprove(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        if (! $actor->holds('ordonnateur') && $eb->workflow_step === 'ordonnateur') {
            $this->assertCurrentActor($actor, $eb);
        }

        if ($eb->workflow_step !== 'ordonnateur' && $eb->status !== EbStatus::Approuvee) {
            $this->assertCurrentActor($actor, $eb);
        }

        if ($eb->status !== EbStatus::Approuvee) {
            if (! $actor->holds('ordonnateur')) {
                throw ValidationException::withMessages([
                    'action' => 'Seul l’ordonnateur peut approuver l’expression de besoin.',
                ]);
            }

            $from = $eb->status->value;
            $eb->forceFill([
                'status' => EbStatus::Approuvee,
                'approved_at' => now(),
                'due_on' => null,
                'expected_actor_label' => 'Génération de l’Engagement',
            ])->save();
            $this->log($eb, $actor, 'approbation', $from, EbStatus::Approuvee->value, null, null, null);
        }

        return $this->performTransform($eb->fresh(), $actor);
    }

    private function performTransform(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        if ($eb->engagement) {
            return $eb;
        }

        if ($eb->status !== EbStatus::Approuvee) {
            throw ValidationException::withMessages([
                'action' => 'L’engagement ne peut être créé qu’après approbation.',
            ]);
        }

        $year = $eb->exercice()->value('annee') ?? now()->year;
        $reference = app(NumberingService::class)->nextEngagement((int) $year);

        $engagement = app(EngagementWorkflow::class)->openFromNeed($eb, $reference);

        $from = $eb->status->value;
        $eb->forceFill([
            'status' => EbStatus::Transformee,
            'workflow_step' => 'clos',
            'expected_actor_label' => $engagement->reference,
            'due_on' => null,
        ])->save();

        $this->log($eb, $actor, 'transformation', $from, EbStatus::Transformee->value, null, null, null, [
            'engagement' => $engagement->reference,
        ]);
        $eb->initiator?->notify(new EbWorkflowNotification($eb, 'transformée en engagement '.$engagement->reference));

        return $eb->fresh(['engagement']);
    }

    private function performCancel(ExpressionBesoin $eb, User $actor, string $motif): ExpressionBesoin
    {
        if ($eb->engagement()->exists() || in_array($eb->status, [EbStatus::Transformee, EbStatus::Annulee, EbStatus::Rejetee], true)) {
            throw ValidationException::withMessages([
                'action' => 'Une EB ayant généré un engagement ne s’annule pas directement : annulez ou dégagez l’engagement.',
            ]);
        }

        if (! $eb->isEditable() && ! $actor->holds('ordonnateur')) {
            throw ValidationException::withMessages([
                'action' => 'Cette expression de besoin ne peut plus être annulée.',
            ]);
        }

        $from = $eb->status->value;
        $eb->forceFill([
            'status' => EbStatus::Annulee,
            'workflow_step' => 'clos',
            'expected_actor_label' => '—',
            'due_on' => null,
        ])->save();

        $this->log($eb, $actor, 'annulation', $from, EbStatus::Annulee->value, $motif, null, null);

        return $eb;
    }

    public function duplicate(ExpressionBesoin $eb, User $actor): ExpressionBesoin
    {
        $eb->load('lines', 'imputations', 'exercice', 'organizationUnit');

        return DB::transaction(function () use ($eb, $actor) {
            $copy = $eb->replicate([
                'reference',
                'status',
                'workflow_step',
                'expected_actor_label',
                'due_on',
                'submitted_at',
                'approved_at',
                'returned_at',
                'rejected_at',
                'return_motif',
                'rejection_motif',
            ]);
            $copy->reference = $this->nextReference($eb->exercice, $eb->organizationUnit);
            $copy->initiator_id = $actor->id;
            $copy->status = EbStatus::Brouillon;
            $copy->workflow_step = 'initiateur';
            $copy->expected_actor_label = 'Initiateur';
            $copy->save();

            foreach ($eb->lines as $line) {
                $clone = $line->replicate();
                $clone->expression_besoin_id = $copy->id;
                $clone->save();
            }

            foreach ($eb->imputations as $imputation) {
                $clone = $imputation->replicate();
                $clone->expression_besoin_id = $copy->id;
                $clone->save();
            }

            $this->log($copy, $actor, 'duplication', null, EbStatus::Brouillon->value, null, null, null, [
                'source' => $eb->reference,
            ]);

            return $copy;
        });
    }

    /**
     * @return array<string, bool>
     */
    public function actionsFor(User $actor, ExpressionBesoin $eb): array
    {
        $editable = $eb->isEditable() && $actor->id === $eb->initiator_id;
        $current = $this->actorMatches($actor, $eb) && ! in_array($eb->status, [
            EbStatus::Brouillon,
            EbStatus::Retournee,
            EbStatus::Approuvee,
            EbStatus::Transformee,
            EbStatus::Rejetee,
            EbStatus::Annulee,
        ], true);

        return [
            'modifier' => $editable,
            'soumettre' => $editable,
            'valider' => $current && $eb->workflow_step !== 'ordonnateur',
            'approuver' => $current && $eb->workflow_step === 'ordonnateur',
            'retourner' => $current,
            'rejeter' => $current,
            'annuler' => $editable || ($actor->holds('ordonnateur') && ! in_array($eb->status, [EbStatus::Transformee, EbStatus::Rejetee, EbStatus::Annulee], true)),
            'dupliquer' => true,
            'pdf' => in_array($eb->status, [EbStatus::Approuvee, EbStatus::Transformee], true),
            'apercu' => true,
            'generer_engagement' => $eb->status === EbStatus::Approuvee && $eb->engagement === null,
        ];
    }

    public function deadlineLabel(ExpressionBesoin $eb): string
    {
        if ($eb->status === EbStatus::Transformee) {
            return 'Engagement créé le '.optional($eb->engagement?->created_at ?? $eb->updated_at)->format('d/m');
        }

        if ($eb->status === EbStatus::Approuvee) {
            return 'Approuvée le '.optional($eb->approved_at)->format('d/m');
        }

        if ($eb->status === EbStatus::Retournee) {
            return 'Retournée le '.optional($eb->returned_at)->format('d/m');
        }

        if ($eb->status === EbStatus::Rejetee) {
            return 'Rejetée le '.optional($eb->rejected_at)->format('d/m');
        }

        if ($eb->status === EbStatus::Brouillon) {
            return 'Non soumise';
        }

        if ($eb->status === EbStatus::Annulee) {
            return 'Annulée';
        }

        if ($eb->due_on && $eb->due_on->isPast() && ! $eb->due_on->isToday()) {
            return 'En retard · +'.$eb->due_on->diffInDays(today()).' j';
        }

        if ($eb->due_on) {
            $days = today()->diffInDays($eb->due_on, false);
            $suffix = $days >= 0 && $days <= 2 ? ' · J-'.$days : '';

            return 'Échéance '.$eb->due_on->format('d/m').$suffix;
        }

        return '';
    }

    public function isLate(ExpressionBesoin $eb): bool
    {
        return $eb->due_on
            && $eb->due_on->lt(today())
            && in_array($eb->status->value, EbStatus::awaiting(), true);
    }

    private function statusForStep(string $step): EbStatus
    {
        return match ($step) {
            'directeur' => EbStatus::Soumise,
            'ordonnateur' => EbStatus::EnApprobation,
            default => EbStatus::EnValidation,
        };
    }

    private function assertEditable(ExpressionBesoin $eb): void
    {
        if (! $eb->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Cette expression de besoin n’est plus modifiable.',
            ]);
        }
    }

    private function assertInitiator(User $actor, ExpressionBesoin $eb): void
    {
        if ($actor->id !== $eb->initiator_id) {
            throw ValidationException::withMessages([
                'action' => 'Seul l’initiateur du dossier peut effectuer cette action.',
            ]);
        }
    }

    private function assertCurrentActor(User $actor, ExpressionBesoin $eb): void
    {
        if (! $this->actorMatches($actor, $eb)) {
            throw ValidationException::withMessages([
                'action' => 'Cette action est réservée à '.$eb->expected_actor_label.'.',
            ]);
        }
    }

    public function allows(User $actor, ExpressionBesoin $eb): bool
    {
        return $this->actorMatches($actor, $eb);
    }

    /**
     * Signale un acteur absent ou plusieurs affectations concurrentes.
     * Le dossier n’est jamais validé automatiquement dans ce cas.
     */
    public function anomalieActeur(ExpressionBesoin $eb): ?string
    {
        $step = (string) $eb->workflow_step;
        if (! in_array($step, ['directeur', 'commissaire', 'secretaire_general', 'ordonnateur'], true)) {
            return null;
        }
        if (! in_array($eb->status?->value, EbStatus::awaiting(), true)) {
            return null;
        }
        $probe = clone $eb;
        $probe->workflow_step = $step;
        $count = User::query()->get()->filter(fn (User $user): bool => $this->actorMatches($user, $probe))->count();
        $label = $this->stepLabel($step, $eb->organizationUnit);
        if ($count === 0) {
            return 'Aucun acteur n’est affecté à « '.$label.' ». Le dossier reste en attente et n’est pas validé automatiquement.';
        }
        if ($count > 1) {
            return $count.' acteurs peuvent traiter « '.$label.' ». Aucun n’est choisi automatiquement.';
        }

        return null;
    }

    private function quantiteTexte(mixed $quantity): string
    {
        if (is_int($quantity) || (is_string($quantity) && preg_match('/^-?\d+(\.\d+)?$/', $quantity) === 1)) {
            return (string) $quantity;
        }

        return number_format((float) $quantity, 4, '.', '');
    }

    private function montantLigne(string $quantite, int $prix): int
    {
        $produit = bcmul($quantite, (string) $prix, 4);
        $entier = bcadd($produit, '0', 0);
        $fraction = bcsub($produit, $entier, 4);
        if (bccomp($fraction, '0.5000', 4) >= 0) {
            return (int) bcadd($entier, '1', 0);
        }

        return (int) $entier;
    }

    private function libelleStructure(string $step, ?OrganizationUnit $unit): string
    {
        if ($unit === null) {
            return '';
        }
        $resolver = app(WorkflowActorResolver::class);
        $competent = $step === 'commissaire'
            ? $resolver->departementTechnique($unit)
            : $resolver->directionCompetente($unit);

        return ' '.($competent?->sigle ?? $unit->sigle);
    }

    private function actorMatches(User $actor, ExpressionBesoin $eb): bool
    {
        if (! $actor->holds((string) $eb->workflow_step)) {
            return false;
        }

        if ($actor->holds('secretaire_general', 'ordonnateur')) {
            return true;
        }

        $unit = $eb->organizationUnit;
        if ($unit === null || $actor->organization_unit_id === null) {
            return false;
        }
        $resolver = app(WorkflowActorResolver::class);
        $ids = match ((string) $eb->workflow_step) {
            'commissaire' => $resolver->unitesCommissaire($unit),
            'directeur' => $resolver->unitesDirection($unit),
            default => [(int) $unit->id],
        };

        return in_array((int) $actor->organization_unit_id, $ids, true);
    }

    /**
     * @param  list<string>|null  $fields
     * @param  array<string, mixed>|null  $payload
     */
    private function log(
        ExpressionBesoin $eb,
        ?User $actor,
        string $action,
        ?string $from,
        ?string $to,
        ?string $motif,
        ?string $observations,
        ?array $fields,
        ?array $payload = null,
    ): EbEvent {
        return app(AuditLogger::class)->record(
            $eb,
            $actor,
            $action,
            $from,
            $to,
            $motif,
            $observations,
            $fields,
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ExpressionBesoin $eb): array
    {
        $eb->loadMissing('lines', 'imputations');

        return [
            'objet' => $eb->objet,
            'justification' => $eb->justification,
            'montant' => $eb->montant,
            'lignes' => $eb->lines->toArray(),
            'imputations' => $eb->imputations->toArray(),
        ];
    }
}
