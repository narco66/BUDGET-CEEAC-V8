<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Administration\Services\ChainWorkflowCatalog;
use App\Domains\Administration\Services\ReferentialReader;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\EngagementDegagement;
use App\Domains\Commitments\Models\EngEvent;
use App\Domains\Commitments\Models\LiqEvent;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Notifications\EngagementWorkflowNotification;
use App\Domains\Ged\Services\GedService;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Integration\IntegrationMessage;
use App\Shared\Support\ExerciceGuard;
use App\Shared\Support\NumberingService;
use App\Shared\Support\TransitionLock;
use App\Shared\Support\WorkingCalendar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngagementWorkflow
{
    /**
     * @var array<int, string>
     */
    private const STEPS = ['expert_budget', 'chef_budget', 'directeur_budget', 'controleur_financier'];

    public function openFromNeed(ExpressionBesoin $eb, string $reference): Engagement
    {
        app(GedService::class)->assertSiBloquant('expression_besoin', $eb->id);
        $engagement = Engagement::query()->create([
            'reference' => $reference,
            'expression_besoin_id' => $eb->id,
            'budget_line_id' => $eb->budget_line_id,
            'montant' => $eb->montant,
            'status' => EngagementStatus::EnInstruction,
            'workflow_step' => 'expert_budget',
            'expected_actor_label' => 'Expert Budget',
            'beneficiary_name' => $eb->lines()->value('beneficiaire'),
            'last_action' => 'Généré automatiquement depuis '.$eb->reference,
            'due_on' => WorkingCalendar::dueIn(5),
            'reserved_at' => now(),
        ]);

        $this->log($engagement, $eb->initiator, 'generation', null, EngagementStatus::EnInstruction->value, null, 'Hérité de '.$eb->reference);

        return $engagement;
    }

    public function updateBeneficiary(Engagement $engagement, User $actor, string $name, ?string $rccm, ?string $nif, ?int $tiersId = null): Engagement
    {
        if ($tiersId !== null) {
            $tiers = Tiers::query()->find($tiersId);
            if ($tiers === null || ! $tiers->isActive()) {
                throw ValidationException::withMessages(['tiers_id' => 'Choisissez un tiers actif du référentiel.']);
            }
            $name = $tiers->raison_sociale;
            $rccm = $tiers->rccm;
            $nif = $tiers->nif;
        }

        return TransitionLock::run($engagement, fn (Engagement $locked) => $this->performUpdateBeneficiary($locked, $actor, $name, $rccm, $nif, $tiersId));
    }

    public function joindrePiece(Engagement $engagement, User $actor, string $type, UploadedFile $file): Engagement
    {
        if (! $actor->holds('expert_budget')) {
            throw ValidationException::withMessages(['action' => 'Seul l’Expert Budget joint une pièce obligatoire.']);
        }
        if (! in_array($engagement->status, [EngagementStatus::EnInstruction, EngagementStatus::Retourne], true) || $engagement->visa_reference !== null) {
            throw ValidationException::withMessages(['action' => 'Une pièce obligatoire se joint avant le visa.']);
        }
        $attendues = app(ReferentialReader::class)->documentLabels('engagement');
        if (! in_array($type, $attendues, true)) {
            throw ValidationException::withMessages(['type' => 'Ce type n’est pas une pièce obligatoire de l’engagement.']);
        }

        $besoin = $engagement->expressionBesoin;
        $path = $file->store('eb-documents/'.$besoin->id);
        $besoin->documents()->create([
            'uploaded_by' => $actor->id,
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
        $this->log($engagement, $actor, 'piece', $engagement->status->value, $engagement->status->value, null, $type);

        return $engagement->fresh();
    }

    public function transmit(Engagement $engagement, User $actor, ?string $observations = null): Engagement
    {
        return TransitionLock::run($engagement, fn (Engagement $locked) => $this->performTransmit($locked, $actor, $observations));
    }

    public function sendBack(Engagement $engagement, User $actor, string $motif, ?string $observations = null): Engagement
    {
        return TransitionLock::run($engagement, fn (Engagement $locked) => $this->performSendBack($locked, $actor, $motif, $observations));
    }

    public function reject(Engagement $engagement, User $actor, string $motif, ?string $observations = null): Engagement
    {
        return TransitionLock::run($engagement, fn (Engagement $locked) => $this->performReject($locked, $actor, $motif, $observations));
    }

    /**
     * Visa du Contrôleur Financier : l’engagement et la ligne budgétaire sont
     * verrouillés, le crédit est recontrôlé et la liquidation n’est ouverte
     * qu’une fois (ENG-002, ENG-007).
     */
    public function vise(Engagement $engagement, User $actor, ?string $observations = null): Engagement
    {
        return TransitionLock::run($engagement, fn (Engagement $locked) => $this->performVise($locked, $actor, $observations));
    }

    /**
     * Dégagement (CDC §9.12, §9.13) : restitue au disponible une part non
     * liquidée d’un engagement visé. L’acte visé n’est pas modifié ; seul
     * l’engagé net diminue. Réservé au Directeur du Budget.
     */
    public function degager(Engagement $engagement, User $actor, int $montant, string $motif, ?string $acte = null): EngagementDegagement
    {
        return DB::transaction(function () use ($engagement, $actor, $montant, $motif, $acte) {
            TransitionLock::rows(BudgetLine::class, [$engagement->budget_line_id]);

            return TransitionLock::run($engagement, function (Engagement $engagement) use ($actor, $montant, $motif, $acte) {
                $this->assertDirecteurBudget($actor);
                if ($engagement->status !== EngagementStatus::TransformeLiquidation || $engagement->visa_reference === null) {
                    throw ValidationException::withMessages(['action' => 'Seul un engagement visé se dégage. Avant visa, rejetez ou annulez l’engagement.']);
                }

                $netAvant = $engagement->montantNet();
                $degageable = max(0, $netAvant - app(LiquidationWorkflow::class)->consumedOn($engagement));
                if ($montant < 1 || $montant > $degageable) {
                    throw ValidationException::withMessages([
                        'montant' => 'Le dégagement doit rester dans la part non liquidée ('.number_format($degageable, 0, ',', ' ').' FCFA).',
                    ]);
                }

                $year = (int) ($engagement->expressionBesoin?->exercice()->value('annee') ?? now()->year);
                $degagement = EngagementDegagement::query()->create([
                    'reference' => app(NumberingService::class)->nextDegagement($year),
                    'engagement_id' => $engagement->id,
                    'montant' => $montant,
                    'engage_net_avant' => $netAvant,
                    'motif' => $motif,
                    'acte' => $acte,
                    'actor_id' => $actor->id,
                ]);
                $engagement->forceFill([
                    'montant_degage' => (int) $engagement->montant_degage + $montant,
                    'last_action' => 'Dégagement '.$degagement->reference,
                ])->save();

                $this->log($engagement, $actor, 'degagement', $engagement->status->value, $engagement->status->value, $motif, $degagement->reference, [
                    'montant' => $montant,
                    'engage_net_avant' => $netAvant,
                    'engage_net_apres' => $engagement->montantNet(),
                ]);
                FinancialAudit::record($actor, 'engagement.degagement', 'engagement', (string) $engagement->id, ['engage_net' => $netAvant], ['engage_net' => $engagement->montantNet(), 'reference' => $degagement->reference], $motif);
                IntegrationMessage::record('ENG-DEG-'.$degagement->id, 'engagement.degage', 'engagement', (string) $engagement->id, ['reference' => $degagement->reference, 'montant' => $montant]);

                return $degagement;
            });
        });
    }

    /**
     * Découpe un engagement encore en instruction : la première part reste sur
     * l’acte d’origine, le solde du besoin ouvre un second engagement.
     */
    public function engagerPartiellement(Engagement $engagement, User $actor, int $montant, string $motif): Engagement
    {
        if (! $actor->holds('expert_budget')) {
            throw ValidationException::withMessages([
                'action' => 'Seul l’Expert Budget engage partiellement un besoin.',
            ]);
        }
        if (! in_array($engagement->status, [EngagementStatus::EnInstruction, EngagementStatus::Retourne], true)) {
            throw ValidationException::withMessages([
                'action' => 'Un engagement partiel se décide avant le visa.',
            ]);
        }
        if (($engagement->nature ?? 'initial') !== 'initial' || $engagement->parent_engagement_id !== null) {
            throw ValidationException::withMessages([
                'action' => 'Seul l’engagement initial d’un besoin peut être fractionné.',
            ]);
        }
        if ($montant < 1 || $montant >= (int) $engagement->montant) {
            throw ValidationException::withMessages([
                'montant' => 'La première part doit être inférieure au montant du besoin.',
            ]);
        }

        return DB::transaction(function () use ($engagement, $actor, $montant, $motif) {
            $reste = (int) $engagement->montant - $montant;
            $engagement->forceFill([
                'montant' => $montant,
                'nature' => 'initial',
                'last_action' => 'Engagement partiel : '.$motif,
            ])->save();
            $this->log($engagement, $actor, 'partiel', $engagement->status->value, $engagement->status->value, $motif, 'Première part');

            return $this->spawn($engagement, $actor, $reste, 'partiel', $motif);
        });
    }

    /**
     * Avenant : engagement supplémentaire sur le même besoin, après visa de
     * l’acte d’origine, dans la limite du crédit disponible.
     */
    public function ouvrirAvenant(Engagement $engagement, User $actor, int $montant, string $motif): Engagement
    {
        if (! $actor->holds('expert_budget') && ! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages([
                'action' => 'Seul l’Expert Budget ou le Directeur du Budget ouvre un avenant.',
            ]);
        }
        if ($engagement->visa_reference === null) {
            throw ValidationException::withMessages([
                'action' => 'Un avenant porte sur un engagement déjà visé.',
            ]);
        }
        if ($montant < 1) {
            throw ValidationException::withMessages(['montant' => 'Indiquez le montant de l’avenant.']);
        }
        ExerciceGuard::assertOpenForLine($engagement->budgetLine);
        $disponible = (int) $engagement->budgetLine?->disponible();
        if ($disponible < $montant) {
            throw ValidationException::withMessages([
                'montant' => 'Le crédit disponible ne couvre pas cet avenant.',
            ]);
        }

        return DB::transaction(function () use ($engagement, $actor, $montant, $motif) {
            $suite = $this->spawn($engagement, $actor, $montant, 'avenant', $motif);
            $suite->forceFill(['avenant_motif' => $motif])->save();

            return $suite->fresh();
        });
    }

    private function spawn(Engagement $engagement, User $actor, int $montant, string $nature, string $motif): Engagement
    {
        $year = (int) ($engagement->expressionBesoin?->exercice()->value('annee') ?? now()->year);
        $suite = Engagement::query()->create([
            'reference' => app(NumberingService::class)->nextEngagement($year),
            'expression_besoin_id' => $engagement->expression_besoin_id,
            'budget_line_id' => $engagement->budget_line_id,
            'montant' => $montant,
            'status' => EngagementStatus::EnInstruction,
            'workflow_step' => 'expert_budget',
            'expected_actor_label' => 'Expert Budget',
            'beneficiary_name' => $engagement->beneficiary_name,
            'beneficiary_rccm' => $engagement->beneficiary_rccm,
            'beneficiary_nif' => $engagement->beneficiary_nif,
            'tiers_id' => $engagement->tiers_id,
            'nature' => $nature,
            'parent_engagement_id' => $engagement->id,
            'last_action' => ($nature === 'avenant' ? 'Avenant de ' : 'Solde de ').$engagement->reference,
            'due_on' => WorkingCalendar::dueIn(5),
            'reserved_at' => now(),
        ]);
        $this->log($suite, $actor, $nature, null, EngagementStatus::EnInstruction->value, $motif, 'Issu de '.$engagement->reference);

        return $suite;
    }

    /**
     * Annulation d’un engagement (CDC §9.13). Avant visa, l’engagement est
     * annulé et son crédit libéré. Après visa, l’annulation n’est possible que
     * si aucune liquidation n’a été visée : les liquidations encore en
     * constitution sont annulées avec lui. Sinon, il faut dégager le reliquat.
     */
    public function annuler(Engagement $engagement, User $actor, string $motif): Engagement
    {
        return DB::transaction(function () use ($engagement, $actor, $motif) {
            TransitionLock::rows(BudgetLine::class, [$engagement->budget_line_id]);

            return TransitionLock::run($engagement, function (Engagement $engagement) use ($actor, $motif) {
                $this->assertDirecteurBudget($actor);
                if (in_array($engagement->status, [EngagementStatus::Annule, EngagementStatus::Rejete], true)) {
                    throw ValidationException::withMessages(['action' => 'Cet engagement est déjà clos.']);
                }

                $liquidations = $engagement->liquidations()
                    ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value])
                    ->get();
                if ($liquidations->contains(fn (Liquidation $row) => $row->visa_reference !== null || $row->status === LiquidationStatus::EnControle)) {
                    throw ValidationException::withMessages([
                        'action' => 'Une liquidation est visée ou en contrôle : l’engagement ne peut plus être annulé. Dégagez le reliquat non liquidé.',
                    ]);
                }

                foreach ($liquidations as $liquidation) {
                    $from = $liquidation->status->value;
                    $liquidation->forceFill([
                        'status' => LiquidationStatus::Annulee,
                        'workflow_step' => 'clos',
                        'expected_actor_label' => '—',
                        'last_action' => 'Annulée avec '.$engagement->reference.' : '.$motif,
                        'due_on' => null,
                    ])->save();
                    LiqEvent::query()->create([
                        'liquidation_id' => $liquidation->id,
                        'actor_id' => $actor->id,
                        'action' => 'annulation',
                        'from_status' => $from,
                        'to_status' => LiquidationStatus::Annulee->value,
                        'motif' => $motif,
                    ]);
                }

                $from = $engagement->status->value;
                $engagement->forceFill([
                    'status' => EngagementStatus::Annule,
                    'workflow_step' => 'clos',
                    'expected_actor_label' => '—',
                    'cancelled_at' => now(),
                    'cancellation_motif' => $motif,
                    'last_action' => 'Annulé : '.$motif,
                    'due_on' => null,
                ])->save();
                $this->log($engagement, $actor, 'annulation', $from, EngagementStatus::Annule->value, $motif);
                FinancialAudit::record($actor, 'engagement.annulation', 'engagement', (string) $engagement->id, ['statut' => $from, 'engage_net' => $engagement->montantNet()], ['statut' => EngagementStatus::Annule->value], $motif);
                IntegrationMessage::record('ENG-ANN-'.$engagement->id, 'engagement.annule', 'engagement', (string) $engagement->id, ['reference' => $engagement->reference]);
                $engagement->expressionBesoin?->initiator?->notify(new EngagementWorkflowNotification($engagement, 'annulé, crédit libéré'));

                return $engagement->fresh();
            });
        });
    }

    public function canDegage(User $actor, Engagement $engagement): bool
    {
        return $actor->holds('directeur_budget') && $engagement->status === EngagementStatus::TransformeLiquidation;
    }

    public function canCancel(User $actor, Engagement $engagement): bool
    {
        return $actor->holds('directeur_budget')
            && ! in_array($engagement->status, [EngagementStatus::Annule, EngagementStatus::Rejete], true);
    }

    private function assertDirecteurBudget(User $actor): void
    {
        if (! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'Le dégagement et l’annulation d’un engagement sont réservés au Directeur du Budget.']);
        }
    }

    private function performUpdateBeneficiary(Engagement $engagement, User $actor, string $name, ?string $rccm, ?string $nif, ?int $tiersId = null): Engagement
    {
        $this->assertOpen($engagement);
        $engagement->forceFill([
            'tiers_id' => $tiersId,
            'beneficiary_name' => $name,
            'beneficiary_rccm' => $rccm,
            'beneficiary_nif' => $nif,
            'last_action' => $tiersId === null ? 'Bénéficiaire complété' : 'Tiers rattaché',
        ])->save();
        $this->log($engagement, $actor, 'beneficiaire', $engagement->status->value, $engagement->status->value);

        return $engagement->fresh();
    }

    private function performTransmit(Engagement $engagement, User $actor, ?string $observations = null): Engagement
    {
        $this->assertActor($actor, $engagement);
        $this->assertOpen($engagement);
        $this->assertCredit($engagement);
        $this->assertBeneficiary($engagement);

        return $this->advance($engagement, $actor, $observations);
    }

    private function performSendBack(Engagement $engagement, User $actor, string $motif, ?string $observations = null): Engagement
    {
        $this->assertActor($actor, $engagement);
        if ($engagement->workflow_step === 'expert_budget') {
            throw ValidationException::withMessages([
                'action' => 'L’expert Budget ne peut pas retourner le dossier à lui-même.',
            ]);
        }

        $from = $engagement->status->value;
        $engagement->forceFill([
            'status' => EngagementStatus::Retourne,
            'workflow_step' => 'expert_budget',
            'expected_actor_label' => 'Expert Budget',
            'return_motif' => $motif,
            'last_action' => 'Retourné : '.$motif,
            'due_on' => WorkingCalendar::dueIn(3),
        ])->save();
        $this->log($engagement, $actor, 'retour', $from, EngagementStatus::Retourne->value, $motif, $observations);

        return $engagement->fresh();
    }

    private function performReject(Engagement $engagement, User $actor, string $motif, ?string $observations = null): Engagement
    {
        $this->assertActor($actor, $engagement);
        if (! in_array($engagement->workflow_step, ['directeur_budget', 'controleur_financier'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Seul le Directeur du Budget ou le Contrôleur Financier peut rejeter.',
            ]);
        }

        $from = $engagement->status->value;
        $engagement->forceFill([
            'status' => EngagementStatus::Rejete,
            'workflow_step' => 'clos',
            'expected_actor_label' => '—',
            'rejection_motif' => $motif,
            'last_action' => 'Rejeté : '.$motif,
            'due_on' => null,
        ])->save();
        $this->log($engagement, $actor, 'rejet', $from, EngagementStatus::Rejete->value, $motif, $observations);
        $engagement->expressionBesoin?->initiator?->notify(new EngagementWorkflowNotification($engagement, 'rejeté, crédit libéré'));

        return $engagement->fresh();
    }

    private function performVise(Engagement $engagement, User $actor, ?string $observations = null): Engagement
    {
        $this->assertActor($actor, $engagement);
        if ($engagement->workflow_step !== 'controleur_financier') {
            throw ValidationException::withMessages([
                'action' => 'Le visa est réservé au Contrôleur Financier.',
            ]);
        }
        ExerciceGuard::assertOpenForLine($engagement->budgetLine);
        $this->assertCredit($engagement);

        return DB::transaction(function () use ($engagement, $actor, $observations) {
            $line = BudgetLine::query()->whereKey($engagement->budget_line_id)->lockForUpdate()->first();
            $engagement->setRelation('budgetLine', $line);
            $this->assertCredit($engagement);
            $year = (int) ($engagement->expressionBesoin?->exercice()->value('annee') ?? now()->year);
            $visa = app(NumberingService::class)->nextVisa($year);
            $liquidationReference = app(NumberingService::class)->nextLiquidation($year);
            $exerciceId = $engagement->expressionBesoin?->exercice_id;
            $garVersionId = $exerciceId === null ? null : Exercice::query()->whereKey($exerciceId)->value('gar_version_id');
            $from = $engagement->status->value;

            $engagement->forceFill([
                'status' => EngagementStatus::TransformeLiquidation,
                'workflow_step' => 'clos',
                'expected_actor_label' => $liquidationReference,
                'visa_reference' => $visa,
                'vised_at' => now(),
                'liquidation_reference' => $liquidationReference,
                'gar_version_id' => $garVersionId,
                'last_action' => 'Visé '.$visa,
                'due_on' => null,
            ])->save();

            app(LiquidationWorkflow::class)->openFromEngagement($engagement, $liquidationReference);

            $this->log($engagement, $actor, 'visa', $from, EngagementStatus::TransformeLiquidation->value, null, $observations, [
                'visa' => $visa,
                'liquidation' => $liquidationReference,
            ]);
            FinancialAudit::record($actor, 'engagement.visa', 'engagement', (string) $engagement->id, null, ['visa' => $visa, 'liquidation' => $liquidationReference]);
            IntegrationMessage::record('ENG-VISA-'.$engagement->id, 'engagement.vise', 'engagement', (string) $engagement->id, ['visa' => $visa, 'liquidation' => $liquidationReference]);
            $engagement->expressionBesoin?->initiator?->notify(new EngagementWorkflowNotification($engagement, 'visé et transmis en liquidation '.$liquidationReference));

            return $engagement->fresh(['liquidation']);
        });
    }

    /**
     * @return array{disponible_avant: int, montant: int, disponible_apres: int, suffisant: bool, insuffisance: int}
     */
    public function credit(Engagement $engagement): array
    {
        $line = $engagement->budgetLine;
        $counted = $engagement->status?->reservesCredit() ?? true;
        $disponibleApres = (int) $line?->disponible();
        $montant = $engagement->montantNet();
        $disponibleAvant = $disponibleApres + ($counted ? $montant : 0);
        $insuffisance = max(0, $montant - $disponibleAvant);

        return [
            'disponible_avant' => $disponibleAvant,
            'montant' => $montant,
            'disponible_apres' => $disponibleAvant - $montant,
            'suffisant' => $insuffisance === 0,
            'insuffisance' => $insuffisance,
        ];
    }

    public function allows(User $actor, Engagement $engagement): bool
    {
        if ($engagement->isLocked()) {
            return false;
        }
        $step = (string) $engagement->workflow_step;
        if ($actor->holds($step)) {
            return true;
        }

        return $step === 'controleur_financier' && $actor->porte('engagement.viser');
    }

    /**
     * @return list<string>
     */
    private function publishedSteps(): array
    {
        $required = self::STEPS;
        $published = app(ChainWorkflowCatalog::class)->roles('eng', $required);
        if ($published === null || ($published[array_key_last($published)] ?? null) !== 'controleur_financier') {
            return $required;
        }

        return $published;
    }

    private function advance(Engagement $engagement, User $actor, ?string $observations): Engagement
    {
        $steps = $this->publishedSteps();
        $index = array_search($engagement->workflow_step, $steps, true);
        $next = $steps[$index + 1] ?? null;
        if ($next === null) {
            throw ValidationException::withMessages([
                'action' => 'Ce dossier attend un visa, pas une simple transmission.',
            ]);
        }

        $status = match ($next) {
            'directeur_budget' => EngagementStatus::AValider,
            'controleur_financier' => EngagementStatus::EnControle,
            default => EngagementStatus::EnInstruction,
        };
        $label = $this->labelFor($next);
        $from = $engagement->status->value;

        $engagement->forceFill([
            'status' => $status,
            'workflow_step' => $next,
            'expected_actor_label' => $label,
            'last_action' => 'Transmis à '.$label,
            'due_on' => WorkingCalendar::dueIn(5),
            'return_motif' => null,
        ])->save();
        $this->log($engagement, $actor, 'transmission', $from, $status->value, null, $observations);

        return $engagement->fresh();
    }

    private function assertCredit(Engagement $engagement): void
    {
        $credit = $this->credit($engagement);
        if (! $credit['suffisant']) {
            throw ValidationException::withMessages([
                'credit' => 'Crédit insuffisant : il manque '.number_format($credit['insuffisance'], 0, ',', ' ').' FCFA sur la ligne '.$engagement->budgetLine?->code.'.',
            ]);
        }
    }

    private function assertBeneficiary(Engagement $engagement): void
    {
        if (blank($engagement->beneficiary_name)) {
            throw ValidationException::withMessages([
                'beneficiaire' => 'Le bénéficiaire est obligatoire avant transmission.',
            ]);
        }
    }

    private function assertOpen(Engagement $engagement): void
    {
        if ($engagement->isLocked()) {
            throw ValidationException::withMessages([
                'action' => 'Cet engagement est verrouillé.',
            ]);
        }
    }

    private function assertActor(User $actor, Engagement $engagement): void
    {
        if (! $this->allows($actor, $engagement)) {
            throw ValidationException::withMessages([
                'action' => 'Cet acteur ne peut pas traiter le dossier à cette étape.',
            ]);
        }
    }

    private function labelFor(string $step): string
    {
        return match ($step) {
            'expert_budget' => 'Expert Budget',
            'chef_budget' => 'Chef de Service Budget',
            'directeur_budget' => 'Directeur du Budget',
            'controleur_financier' => 'Contrôleur Financier',
            default => $step,
        };
    }

    /**
     * @param  array<string, mixed>|null  $fields
     */
    private function log(
        Engagement $engagement,
        ?User $actor,
        string $action,
        ?string $from,
        ?string $to,
        ?string $motif = null,
        ?string $observations = null,
        ?array $fields = null,
    ): void {
        EngEvent::query()->create([
            'engagement_id' => $engagement->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'motif' => $motif,
            'observations' => $observations,
            'fields' => $fields,
        ]);
    }
}
