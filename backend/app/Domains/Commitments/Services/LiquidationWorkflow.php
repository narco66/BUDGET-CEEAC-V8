<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\LiqEvent;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\LiquidationRectification;
use App\Domains\Commitments\Notifications\LiquidationWorkflowNotification;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Integration\IntegrationMessage;
use App\Shared\Support\ExerciceGuard;
use App\Shared\Support\NumberingService;
use App\Shared\Support\TransitionLock;
use App\Shared\Support\WorkingCalendar;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiquidationWorkflow
{
    public function openFromEngagement(Engagement $engagement, string $reference): Liquidation
    {
        $initiator = $engagement->expressionBesoin?->initiator;
        $liquidation = Liquidation::query()->create([
            'reference' => $reference,
            'engagement_id' => $engagement->id,
            'montant' => 0,
            'status' => LiquidationStatus::Generee,
            'workflow_step' => 'initiateur',
            'expected_actor_label' => $initiator?->name ?? 'Structure initiatrice',
            'fournisseur' => $engagement->beneficiary_name,
            'last_action' => 'Générée au visa de '.$engagement->reference,
            'due_on' => WorkingCalendar::dueIn(5),
        ]);

        $this->log($liquidation, $initiator, 'generation', null, LiquidationStatus::Generee->value, null, 'Héritée de '.$engagement->reference);

        return $liquidation;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function certify(Liquidation $liquidation, User $actor, int $montantAccepte, ?string $reserves, array $details = []): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performCertify($locked, $actor, $montantAccepte, $reserves, $details));
    }

    /**
     * @param  array{numero: string, date: string, echeance?: string|null, montant_ht: int, taxes: int, retenue: int, penalite: int}  $invoice
     */
    public function saveInvoice(Liquidation $liquidation, User $actor, array $invoice): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performSaveInvoice($locked, $actor, $invoice));
    }

    public function submit(Liquidation $liquidation, User $actor): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performSubmit($locked, $actor));
    }

    public function requestDuplicateReview(Liquidation $liquidation, User $actor, string $motif): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performRequestDuplicateReview($locked, $actor, $motif));
    }

    public function sendBack(Liquidation $liquidation, User $actor, string $motif, ?string $observations = null): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performSendBack($locked, $actor, $motif, $observations));
    }

    public function requestComplement(Liquidation $liquidation, User $actor, string $motif, ?string $observations = null): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performRequestComplement($locked, $actor, $motif, $observations));
    }

    public function reject(Liquidation $liquidation, User $actor, string $motif, ?string $observations = null): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performReject($locked, $actor, $motif, $observations));
    }

    /**
     * Visa : cumul recontrôlé sous verrou de l’engagement, ordonnancement
     * généré ou représenté une seule fois (LIQ-003, LIQ-006).
     */
    public function vise(Liquidation $liquidation, User $actor, ?string $observations = null): Liquidation
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performVise($locked, $actor, $observations));
    }

    public function rectify(Liquidation $liquidation, User $actor, string $kind, int $amount, string $motif): LiquidationRectification
    {
        return $this->locked($liquidation, fn (Liquidation $locked) => $this->performRectify($locked, $actor, $kind, $amount, $motif));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function performCertify(Liquidation $liquidation, User $actor, int $montantAccepte, ?string $reserves, array $details = []): Liquidation
    {
        $this->assertInitiator($actor, $liquidation);
        $this->assertOpen($liquidation);
        $lines = $details['lignes'] ?? null;
        if (is_array($lines) && $lines !== []) {
            $montantAccepte = $this->amountFromLines($lines);
        }
        $plafond = $this->remaining($liquidation) + (int) $liquidation->montant_brut;
        if ($montantAccepte < 1 || $montantAccepte > $plafond) {
            throw ValidationException::withMessages([
                'montant_accepte' => 'Le montant accepté doit rester dans le reliquat de l’engagement ('.number_format($plafond, 0, ',', ' ').' FCFA).',
            ]);
        }

        $from = $liquidation->status->value;
        $liquidation->forceFill([
            'status' => LiquidationStatus::EnPreparation,
            'workflow_step' => 'initiateur',
            'expected_actor_label' => $actor->name,
            'service_fait_at' => filled($details['date_service'] ?? null) ? $details['date_service'] : now(),
            'certified_by' => $actor->id,
            'service_fait_reserves' => $reserves,
            'bon_livraison' => $details['bon_livraison'] ?? null,
            'nature_prestation' => $details['nature_prestation'] ?? null,
            'lieu_reception' => $details['lieu_reception'] ?? null,
            'service_lignes' => $lines,
            'montant_accepte' => $montantAccepte,
            'last_action' => filled($reserves) ? 'Service fait certifié avec réserve' : 'Service fait certifié',
            'due_on' => WorkingCalendar::dueIn(5),
        ])->save();
        $this->log($liquidation, $actor, 'service_fait', $from, LiquidationStatus::EnPreparation->value, null, $reserves);

        return $liquidation->fresh();
    }

    /**
     * @param  array{numero: string, date: string, echeance?: string|null, montant_ht: int, taxes: int, retenue: int, penalite: int}  $invoice
     */
    private function performSaveInvoice(Liquidation $liquidation, User $actor, array $invoice): Liquidation
    {
        $this->assertInitiator($actor, $liquidation);
        $this->assertOpen($liquidation);
        if ($liquidation->service_fait_at === null) {
            throw ValidationException::withMessages([
                'service_fait' => 'Certifiez le service fait avant d’enregistrer la facture.',
            ]);
        }

        $ttc = $invoice['montant_ht'] + $invoice['taxes'];
        $brut = min($ttc, (int) $liquidation->montant_accepte);
        $this->assertWithinEngagement($liquidation, $brut);

        $deductions = $invoice['retenue'] + $invoice['penalite'];
        if ($deductions > $brut) {
            throw ValidationException::withMessages([
                'retenue' => 'Les retenues et pénalités ne peuvent pas dépasser le montant brut liquidable.',
            ]);
        }

        $net = $brut - $deductions;
        $doublon = $this->isDuplicate($liquidation, $invoice['numero']);
        $from = $liquidation->status->value;
        $liquidation->forceFill([
            'invoice_number' => $invoice['numero'],
            'invoice_date' => $invoice['date'],
            'invoice_due' => $invoice['echeance'] ?? null,
            'montant_ht' => $invoice['montant_ht'],
            'taxes' => $invoice['taxes'],
            'montant_ttc' => $ttc,
            'montant_brut' => $brut,
            'retenue_garantie' => $invoice['retenue'],
            'penalite' => $invoice['penalite'],
            'montant_net' => $net,
            'montant' => $net,
            'doublon' => $doublon,
            'last_action' => $doublon ? 'Doublon de facture détecté' : 'Facture enregistrée',
        ])->save();
        $this->log($liquidation, $actor, 'facture', $from, $liquidation->status->value, null, $invoice['numero']);

        return $liquidation->fresh();
    }

    private function performSubmit(Liquidation $liquidation, User $actor): Liquidation
    {
        $this->assertInitiator($actor, $liquidation);
        $this->assertOpen($liquidation);
        $errors = [];
        if ($liquidation->service_fait_at === null) {
            $errors['service_fait'] = 'Le service fait n’est pas certifié.';
        }
        if (blank($liquidation->invoice_number) || (int) $liquidation->montant_net < 1) {
            $errors['facture'] = 'La facture et le net à payer sont obligatoires.';
        }
        if ($liquidation->doublon) {
            $errors['facture'] = 'Cette facture semble avoir déjà été enregistrée dans une liquidation. La soumission est bloquée.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $from = $liquidation->status->value;
        $liquidation->forceFill([
            'status' => LiquidationStatus::EnControle,
            'workflow_step' => 'controleur_financier',
            'expected_actor_label' => 'Contrôleur Financier',
            'last_action' => 'Transmise au Contrôleur Financier',
            'due_on' => WorkingCalendar::dueIn(3),
            'return_motif' => null,
        ])->save();
        $this->log($liquidation, $actor, 'soumission', $from, LiquidationStatus::EnControle->value);

        return $liquidation->fresh();
    }

    private function performRequestDuplicateReview(Liquidation $liquidation, User $actor, string $motif): Liquidation
    {
        $this->assertInitiator($actor, $liquidation);
        if (! $liquidation->doublon) {
            throw ValidationException::withMessages([
                'facture' => 'Aucun doublon n’est détecté sur ce dossier.',
            ]);
        }

        $liquidation->forceFill([
            'last_action' => 'Demande d’autorisation de doublon consignée',
        ])->save();
        $this->log($liquidation, $actor, 'demande_doublon', $liquidation->status->value, $liquidation->status->value, $motif, 'La soumission reste bloquée.');

        return $liquidation->fresh();
    }

    private function performSendBack(Liquidation $liquidation, User $actor, string $motif, ?string $observations = null): Liquidation
    {
        return $this->returnToInitiator($liquidation, $actor, LiquidationStatus::Retournee, 'retour', $motif, $observations);
    }

    private function performRequestComplement(Liquidation $liquidation, User $actor, string $motif, ?string $observations = null): Liquidation
    {
        return $this->returnToInitiator($liquidation, $actor, LiquidationStatus::Complement, 'complement', $motif, $observations);
    }

    private function performReject(Liquidation $liquidation, User $actor, string $motif, ?string $observations = null): Liquidation
    {
        $this->assertController($actor, $liquidation);
        $from = $liquidation->status->value;
        $liquidation->forceFill([
            'status' => LiquidationStatus::Rejetee,
            'workflow_step' => 'clos',
            'expected_actor_label' => '—',
            'rejection_motif' => $motif,
            'last_action' => 'Rejetée : '.$motif,
            'due_on' => null,
        ])->save();
        $this->log($liquidation, $actor, 'rejet', $from, LiquidationStatus::Rejetee->value, $motif, $observations);
        $liquidation->engagement?->expressionBesoin?->initiator?->notify(new LiquidationWorkflowNotification($liquidation, 'rejetée'));

        return $liquidation->fresh();
    }

    private function performVise(Liquidation $liquidation, User $actor, ?string $observations = null): Liquidation
    {
        $this->assertController($actor, $liquidation);
        if ($liquidation->doublon || $liquidation->service_fait_at === null || (int) $liquidation->montant_net < 1) {
            throw ValidationException::withMessages([
                'action' => 'Le visa exige un service fait certifié, une facture valide et l’absence de doublon.',
            ]);
        }
        ExerciceGuard::assertOpenForLine($liquidation->engagement?->budgetLine);
        $this->assertWithinEngagement($liquidation, (int) $liquidation->montant_brut);

        return DB::transaction(function () use ($liquidation, $actor, $observations) {
            $year = (int) ($liquidation->engagement?->expressionBesoin?->exercice()->value('annee') ?? now()->year);
            $visa = app(NumberingService::class)->nextLiquidationVisa($year);
            $existing = $liquidation->ordonnancement;
            $ordre = $existing?->reference ?? app(NumberingService::class)->nextOrdonnancement($year);
            $from = $liquidation->status->value;

            $liquidation->forceFill([
                'status' => LiquidationStatus::TransformeeOrdonnancement,
                'workflow_step' => 'clos',
                'expected_actor_label' => $ordre,
                'visa_reference' => $visa,
                'vised_at' => now(),
                'ordonnancement_reference' => $ordre,
                'last_action' => 'Visée '.$visa,
                'due_on' => null,
            ])->save();

            $ordonnancement = app(OrdonnancementWorkflow::class);
            if ($existing) {
                $ordonnancement->present($existing, $liquidation);
            } else {
                $ordonnancement->openFromLiquidation($liquidation, $ordre);
            }

            $this->log($liquidation, $actor, 'visa', $from, LiquidationStatus::TransformeeOrdonnancement->value, null, $observations);
            FinancialAudit::record($actor, 'liquidation.visa', 'liquidation', (string) $liquidation->id, ['statut' => $from], ['visa' => $visa, 'ordonnancement' => $ordre]);
            IntegrationMessage::record('LIQ-VISA-'.$liquidation->id, 'liquidation.visee', 'liquidation', (string) $liquidation->id, ['visa' => $visa, 'ordonnancement' => $ordre]);
            $liquidation->engagement?->expressionBesoin?->initiator?->notify(new LiquidationWorkflowNotification($liquidation, 'visée et transmise en ordonnancement '.$ordre));

            return $liquidation->fresh(['ordonnancement']);
        });
    }

    private function performRectify(Liquidation $liquidation, User $actor, string $kind, int $amount, string $motif): LiquidationRectification
    {
        if (! $actor->holds('controleur_financier')) {
            throw ValidationException::withMessages([
                'action' => 'Seul le Contrôleur Financier rectifie une liquidation visée.',
            ]);
        }
        if ($liquidation->status !== LiquidationStatus::TransformeeOrdonnancement || $liquidation->visa_reference === null) {
            throw ValidationException::withMessages([
                'action' => 'Une rectification ne s’applique qu’à une liquidation déjà visée.',
            ]);
        }
        if (! in_array($kind, ['avoir', 'complementaire'], true) || $amount < 1) {
            throw ValidationException::withMessages(['montant' => 'Indiquez un avoir ou un complément d’un montant positif.']);
        }
        if ($kind === 'avoir') {
            $already = (int) $liquidation->rectifications()->where('kind', 'avoir')->sum('amount');
            if ($already + $amount > (int) $liquidation->montant_net) {
                throw ValidationException::withMessages(['montant' => 'L’avoir ne peut pas dépasser le net visé.']);
            }
        }
        if ($kind === 'complementaire') {
            $this->assertWithinEngagement($liquidation, (int) $liquidation->montant_brut, $amount);
        }

        return DB::transaction(function () use ($liquidation, $actor, $kind, $amount, $motif) {
            $netBefore = (int) $liquidation->montant_net;
            $year = (int) now()->year;
            $rectification = LiquidationRectification::query()->create([
                'reference' => app(NumberingService::class)->nextRectification($year),
                'liquidation_id' => $liquidation->id,
                'kind' => $kind,
                'amount' => $amount,
                'motif' => $motif,
                'actor_id' => $actor->id,
            ]);
            $liquidation->forceFill([
                'last_action' => ($kind === 'avoir' ? 'Avoir ' : 'Complément ').$rectification->reference,
            ])->save();
            $this->alignOpenDownstream($liquidation);
            $this->rectifyIssuedDownstream($liquidation, $kind, $amount);
            $this->log($liquidation, $actor, 'rectification', $liquidation->status->value, $liquidation->status->value, $motif, $rectification->reference);
            FinancialAudit::record($actor, 'liquidation.rectification', 'liquidation', (string) $liquidation->id, ['montant_net' => $netBefore], ['reference' => $rectification->reference, 'kind' => $kind, 'montant' => $amount, 'montant_net' => (int) $liquidation->fresh()->montant_net], $motif);
            IntegrationMessage::record('LIQ-RECTIF-'.$rectification->id, 'liquidation.rectifiee', 'liquidation_rectification', (string) $rectification->id, ['liquidation' => $liquidation->reference, 'kind' => $kind, 'montant' => $amount]);

            return $rectification;
        });
    }

    public function openNext(Engagement $engagement, User $actor): Liquidation
    {
        return TransitionLock::run($engagement, fn (Engagement $locked) => $this->performOpenNext($locked, $actor));
    }

    private function performOpenNext(Engagement $engagement, User $actor): Liquidation
    {
        ExerciceGuard::assertOpenForLine($engagement->budgetLine);
        $initiatorId = $engagement->expressionBesoin?->initiator_id;
        if ($actor->id !== $initiatorId) {
            throw ValidationException::withMessages([
                'action' => 'Seule la structure initiatrice peut ouvrir la liquidation suivante.',
            ]);
        }
        if ($engagement->liquidations()->whereIn('status', [
            LiquidationStatus::Generee->value,
            LiquidationStatus::EnPreparation->value,
            LiquidationStatus::EnControle->value,
            LiquidationStatus::Complement->value,
            LiquidationStatus::Retournee->value,
        ])->exists()) {
            throw ValidationException::withMessages([
                'action' => 'Une liquidation est déjà ouverte sur cet engagement.',
            ]);
        }

        $reliquat = $engagement->montantNet() - $this->liquidatedOn($engagement);
        if ($reliquat < 1) {
            throw ValidationException::withMessages([
                'action' => 'L’engagement est entièrement liquidé.',
            ]);
        }

        $year = (int) ($engagement->expressionBesoin?->exercice()->value('annee') ?? now()->year);
        $reference = app(NumberingService::class)->nextLiquidation($year);

        return $this->openFromEngagement($engagement, $reference);
    }

    public function isInitiator(User $actor, Liquidation $liquidation): bool
    {
        return $actor->id === $liquidation->engagement?->expressionBesoin?->initiator_id
            && $liquidation->workflow_step === 'initiateur'
            && ! $liquidation->isLocked();
    }

    public function isController(User $actor, Liquidation $liquidation): bool
    {
        return $actor->porte('liquidation.viser')
            && $liquidation->workflow_step === 'controleur_financier'
            && ! $liquidation->isLocked();
    }

    public function liquidatedOn(Engagement $engagement): int
    {
        return (int) $engagement->liquidations()
            ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value])
            ->sum('montant_brut');
    }

    /**
     * Aligne le montant d’un ordonnancement encore ouvert et d’un paiement non
     * exécuté. Le net visé de la liquidation reste inchangé.
     */
    private function alignOpenDownstream(Liquidation $liquidation): void
    {
        $avoirs = (int) $liquidation->rectifications()->where('kind', 'avoir')->sum('amount');
        $complements = (int) $liquidation->rectifications()->where('kind', 'complementaire')->sum('amount');
        $netDu = (int) $liquidation->montant_net - $avoirs + $complements;
        if ($netDu < 0) {
            return;
        }

        $ordre = $liquidation->ordonnancement;
        if ($liquidation->ordonnancements()->count() !== 1) {
            return;
        }
        if ($ordre !== null && in_array($ordre->status, [OrdonnancementStatus::ASigner, OrdonnancementStatus::Retourne], true)) {
            $ordre->forceFill([
                'montant' => $netDu,
                'last_action' => 'Montant aligné sur la rectification de liquidation',
            ])->save();
        }

        $paiement = $ordre?->paiement;
        $ouvert = [
            PaiementStatus::Genere,
            PaiementStatus::EnPreparation,
            PaiementStatus::AControler,
            PaiementStatus::ASigner,
            PaiementStatus::Retourne,
            PaiementStatus::Suspendu,
        ];
        if ($paiement !== null && (int) $paiement->montant_paye === 0 && in_array($paiement->status, $ouvert, true)) {
            $paiement->forceFill([
                'montant' => $netDu,
                'last_action' => 'Montant aligné sur la rectification de liquidation',
            ])->save();
        }
    }

    /**
     * Un acte déjà signé n’est pas réécrit. L’avoir ou le complément ouvre un
     * ordre correctif à signer. Un paiement déjà exécuté porte le montant à recouvrer.
     */
    private function rectifyIssuedDownstream(Liquidation $liquidation, string $kind, int $amount): void
    {
        $ordre = $liquidation->ordonnancement;
        if ($ordre === null || in_array($ordre->status, [OrdonnancementStatus::ASigner, OrdonnancementStatus::Retourne], true)) {
            return;
        }

        $nature = $kind === 'avoir' ? 'rectificatif' : 'complementaire';
        app(OrdonnancementWorkflow::class)->openCorrective($liquidation, $nature, $amount);

        $paiement = $ordre->paiement;
        if ($paiement === null || $kind !== 'avoir') {
            return;
        }

        $ouvert = [
            PaiementStatus::Genere,
            PaiementStatus::EnPreparation,
            PaiementStatus::AControler,
            PaiementStatus::ASigner,
            PaiementStatus::Retourne,
            PaiementStatus::Suspendu,
        ];
        if ((int) $paiement->montant_paye === 0 && in_array($paiement->status, $ouvert, true)) {
            $paiement->forceFill([
                'montant' => max(0, (int) $paiement->montant - $amount),
                'last_action' => 'Montant réduit par un avoir sur un ordre déjà signé',
            ])->save();

            return;
        }

        if ((int) $paiement->montant_paye > 0) {
            $paiement->forceFill([
                'montant_a_recouvrer' => (int) $paiement->montant_a_recouvrer + $amount,
                'last_action' => 'Avoir à recouvrer sur un paiement déjà émis',
            ])->save();
        }
    }

    /**
     * Part de l’engagement déjà consommée : liquidations actives (y compris en
     * cours de constitution) et compléments de liquidation.
     */
    public function consumedOn(Engagement $engagement): int
    {
        $complements = (int) LiquidationRectification::query()
            ->where('kind', 'complementaire')
            ->whereHas('liquidation', fn ($query) => $query
                ->where('engagement_id', $engagement->id)
                ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value]))
            ->sum('amount');

        return $this->liquidatedOn($engagement) + $complements;
    }

    private function returnToInitiator(Liquidation $liquidation, User $actor, LiquidationStatus $status, string $action, string $motif, ?string $observations): Liquidation
    {
        $this->assertController($actor, $liquidation);
        $initiator = $liquidation->engagement?->expressionBesoin?->initiator;
        $from = $liquidation->status->value;
        $liquidation->forceFill([
            'status' => $status,
            'workflow_step' => 'initiateur',
            'expected_actor_label' => $initiator?->name ?? 'Structure initiatrice',
            'return_motif' => $motif,
            'last_action' => $status->label().' : '.$motif,
            'due_on' => WorkingCalendar::dueIn(3),
        ])->save();
        $this->log($liquidation, $actor, $action, $from, $status->value, $motif, $observations);

        return $liquidation->fresh();
    }

    private function remaining(Liquidation $liquidation): int
    {
        return max(0, $liquidation->engagement->montantNet() - $this->liquidatedByOthers($liquidation) - (int) $liquidation->montant_brut);
    }

    /**
     * Cumul liquidé (liquidations actives et compléments visés) ≤ engagement (LIQ-003).
     */
    private function assertWithinEngagement(Liquidation $liquidation, int $brut, int $complement = 0): void
    {
        $plafond = $liquidation->engagement->montantNet();
        $complements = (int) LiquidationRectification::query()
            ->where('kind', 'complementaire')
            ->whereHas('liquidation', fn ($query) => $query
                ->where('engagement_id', $liquidation->engagement_id)
                ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value]))
            ->sum('amount');

        if ($brut + $this->liquidatedByOthers($liquidation) + $complements + $complement > $plafond) {
            throw ValidationException::withMessages([
                'montant' => 'Le cumul liquidé dépasserait l’engagement de '.number_format($plafond, 0, ',', ' ').' FCFA.',
            ]);
        }
    }

    /**
     * Verrouille l’engagement puis la liquidation : toutes les opérations qui
     * consomment le reliquat d’un engagement sont ainsi sérialisées.
     *
     * @template TResult
     *
     * @param  Closure(Liquidation): TResult  $callback
     * @return TResult
     */
    private function locked(Liquidation $liquidation, Closure $callback): mixed
    {
        return DB::transaction(function () use ($liquidation, $callback) {
            TransitionLock::rows(Engagement::class, [$liquidation->engagement_id]);

            return TransitionLock::run($liquidation, $callback);
        });
    }

    private function liquidatedByOthers(Liquidation $liquidation): int
    {
        return (int) Liquidation::query()
            ->where('engagement_id', $liquidation->engagement_id)
            ->whereKeyNot($liquidation->id)
            ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value])
            ->sum('montant_brut');
    }

    private function isDuplicate(Liquidation $liquidation, string $number): bool
    {
        return Liquidation::query()
            ->whereKeyNot($liquidation->id)
            ->where('invoice_number', $number)
            ->where('fournisseur', $liquidation->fournisseur)
            ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value])
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function amountFromLines(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $delivered = (float) $line['quantite_livree'];
            $accepted = (float) $line['quantite_acceptee'];
            if ($accepted > $delivered) {
                throw ValidationException::withMessages([
                    'lignes' => 'La quantité acceptée ne peut pas dépasser la quantité livrée.',
                ]);
            }
            $total += (int) round($accepted * (int) $line['prix_unitaire']);
        }

        return $total;
    }

    private function assertOpen(Liquidation $liquidation): void
    {
        if ($liquidation->isLocked() || $liquidation->workflow_step !== 'initiateur') {
            throw ValidationException::withMessages([
                'action' => 'Ce dossier n’est pas modifiable à cette étape.',
            ]);
        }
    }

    private function assertInitiator(User $actor, Liquidation $liquidation): void
    {
        if (! $this->isInitiator($actor, $liquidation)) {
            throw ValidationException::withMessages([
                'action' => 'Seule la structure initiatrice peut constituer ce dossier.',
            ]);
        }
    }

    private function assertController(User $actor, Liquidation $liquidation): void
    {
        if (! $this->isController($actor, $liquidation)) {
            throw ValidationException::withMessages([
                'action' => 'Cette décision est réservée au Contrôleur Financier.',
            ]);
        }
    }

    private function log(Liquidation $liquidation, ?User $actor, string $action, ?string $from, ?string $to, ?string $motif = null, ?string $observations = null): void
    {
        LiqEvent::query()->create([
            'liquidation_id' => $liquidation->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'motif' => $motif,
            'observations' => $observations,
        ]);
    }
}
