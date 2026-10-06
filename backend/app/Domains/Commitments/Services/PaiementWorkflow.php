<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Commitments\Models\PayEvent;
use App\Domains\Commitments\Models\PayLot;
use App\Domains\Commitments\Notifications\PaiementWorkflowNotification;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Domains\Suppliers\Services\TiersService;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Integration\IntegrationMessage;
use App\Shared\Support\NumberingService;
use App\Shared\Support\TransitionLock;
use App\Shared\Support\WorkingCalendar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaiementWorkflow
{
    public function openFromOrdonnancement(Ordonnancement $ordonnancement, string $reference): Paiement
    {
        $paiement = Paiement::query()->create([
            'reference' => $reference,
            'ordonnancement_id' => $ordonnancement->id,
            'montant' => $ordonnancement->montant,
            'montant_paye' => 0,
            'status' => PaiementStatus::Genere,
            'workflow_step' => 'comptable',
            'expected_actor_label' => 'Comptable · Agence Comptable',
            'last_action' => 'Transmis depuis '.$ordonnancement->reference,
            'due_on' => WorkingCalendar::dueIn(3),
        ]);
        $this->log($paiement, null, 'generation', null, PaiementStatus::Genere->value, null, $ordonnancement->reference);

        return $paiement;
    }

    public function prendreEnCharge(Paiement $paiement, User $actor): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor) {
            $this->assertComptable($actor);
            $this->assertStatus($paiement, [PaiementStatus::Genere]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::EnPreparation,
                'workflow_step' => 'comptable',
                'expected_actor_label' => $actor->name,
                'pris_en_charge_at' => now(),
                'pris_en_charge_par' => $actor->id,
                'last_action' => 'Pris en charge par '.$actor->name,
                'due_on' => WorkingCalendar::dueIn(2),
            ])->save();
            $this->log($paiement, $actor, 'prise_en_charge', $from, PaiementStatus::EnPreparation->value);

            return $paiement->fresh();
        });
    }

    /**
     * Préparation du règlement. Pour un virement ou un chèque, les coordonnées
     * ne sont pas saisies : elles proviennent d’un compte validé du tiers
     * attendu et sont figées dans le paiement (snapshot, CDC §12.11, §39).
     *
     * @param  array{mode: string, compte_bancaire_id?: int|null, compte_ceeac?: string|null, motif?: string|null}  $data
     */
    public function preparer(Paiement $paiement, User $actor, array $data): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $data) {
            $this->assertComptable($actor);
            $this->assertStatus($paiement, [PaiementStatus::EnPreparation, PaiementStatus::Retourne]);
            $mode = (string) ($data['mode'] ?? '');
            $account = in_array($mode, ['virement', 'cheque'], true)
                ? $this->eligibleAccount($paiement, isset($data['compte_bancaire_id']) ? (int) $data['compte_bancaire_id'] : null)
                : null;
            $this->assertMode($paiement, [
                'mode' => $mode,
                'banque' => $account?->banque,
                'compte' => $account?->numero,
                'titulaire' => $account?->titulaire,
            ]);
            $before = $paiement->only(['mode', 'banque', 'agence', 'compte', 'titulaire']);
            $paiement->forceFill([
                'mode' => $mode,
                'tiers_bank_account_id' => $account?->id,
                'banque' => $account?->banque,
                'agence' => $account?->agence,
                'compte' => $account?->numero,
                'titulaire' => $account?->titulaire,
                'compte_ceeac' => $data['compte_ceeac'] ?? null,
                'compte_modifie' => $account?->isUnderVigilance() ?? false,
                'motif_reglement' => $data['motif'] ?? null,
                'status' => PaiementStatus::EnPreparation,
                'last_action' => 'Règlement préparé · '.$mode,
            ])->save();
            $this->log($paiement, $actor, 'preparation', PaiementStatus::EnPreparation->value, PaiementStatus::EnPreparation->value, null, $data['mode']);
            $after = $paiement->only(['mode', 'banque', 'agence', 'compte', 'titulaire']);
            if ($before !== $after) {
                FinancialAudit::record($actor, 'paiement.coordonnees', 'paiement', (string) $paiement->id, $before, $after);
            }

            return $paiement->fresh();
        });
    }

    public function soumettre(Paiement $paiement, User $actor): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor) {
            $this->assertComptable($actor);
            $this->assertStatus($paiement, [PaiementStatus::EnPreparation, PaiementStatus::Retourne]);
            if (blank($paiement->mode)) {
                throw ValidationException::withMessages(['mode' => 'Le mode de règlement doit être enregistré avant la soumission.']);
            }
            $this->assertMode($paiement, [
                'mode' => $paiement->mode,
                'banque' => $paiement->banque,
                'compte' => $paiement->compte,
                'titulaire' => $paiement->titulaire,
            ]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::AControler,
                'workflow_step' => 'chef_comptable',
                'expected_actor_label' => 'Chef Comptable',
                'last_action' => 'Soumis au Chef Comptable',
                'return_motif' => null,
                'due_on' => WorkingCalendar::dueIn(2),
            ])->save();
            $this->log($paiement, $actor, 'soumission', $from, PaiementStatus::AControler->value);

            return $paiement->fresh();
        });
    }

    public function valider(Paiement $paiement, User $actor): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor) {
            $this->assertChef($actor);
            $this->assertStatus($paiement, [PaiementStatus::AControler]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::ASigner,
                'workflow_step' => 'agent_comptable',
                'expected_actor_label' => 'Agent Comptable',
                'validated_at' => now(),
                'last_action' => 'Validé par le Chef Comptable',
                'due_on' => now()->addDay()->toDateString(),
            ])->save();
            $this->log($paiement, $actor, 'validation', $from, PaiementStatus::ASigner->value);

            return $paiement->fresh();
        });
    }

    public function signer(Paiement $paiement, User $actor, bool $confirmed): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $confirmed) {
            if (! $actor->porte('paiement.signer')) {
                throw ValidationException::withMessages([
                    'action' => 'L’autorisation de paiement exige la permission paiement.signer.',
                ]);
            }
            $this->assertStatus($paiement, [PaiementStatus::ASigner]);
            if (! $confirmed) {
                throw ValidationException::withMessages(['confirmation' => 'La validation du règlement est obligatoire.']);
            }
            foreach ($this->controls($paiement) as $control) {
                if ($control['bloquant'] && ! $control['ok']) {
                    throw ValidationException::withMessages(['action' => $control['point'].' · '.$control['detail']]);
                }
            }
            $account = $paiement->bankAccount;
            if ($account !== null && in_array($actor->id, [$account->created_by, $account->validated_by], true)) {
                throw ValidationException::withMessages([
                    'action' => 'Séparation des fonctions : vous avez saisi ou validé le compte bénéficiaire, vous ne pouvez pas autoriser ce paiement.',
                ]);
            }
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::Autorise,
                'workflow_step' => 'comptable',
                'expected_actor_label' => 'Comptable · exécution',
                'signed_at' => now(),
                'signed_by' => $actor->id,
                'last_action' => 'Autorisé par l’Agent Comptable',
                'due_on' => WorkingCalendar::dueIn(2),
            ])->save();
            $this->log($paiement, $actor, 'signature', $from, PaiementStatus::Autorise->value);

            return $paiement->fresh();
        });
    }

    public function retourner(Paiement $paiement, User $actor, string $motif): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $motif) {
            if (! $actor->holds('chef_comptable', 'agent_comptable')) {
                throw ValidationException::withMessages(['action' => 'Le retour est réservé au Chef Comptable ou à l’Agent Comptable.']);
            }
            $this->assertStatus($paiement, [PaiementStatus::AControler, PaiementStatus::ASigner]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::Retourne,
                'workflow_step' => 'comptable',
                'expected_actor_label' => 'Comptable · Agence Comptable',
                'return_motif' => $motif,
                'last_action' => 'Retourné : '.$motif,
                'due_on' => WorkingCalendar::dueIn(2),
            ])->save();
            $this->log($paiement, $actor, 'retour', $from, PaiementStatus::Retourne->value, $motif);

            return $paiement->fresh();
        });
    }

    public function rejeter(Paiement $paiement, User $actor, string $motif): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $motif) {
            $this->assertAgent($actor);
            $this->assertStatus($paiement, [PaiementStatus::ASigner]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::Rejete,
                'workflow_step' => 'clos',
                'expected_actor_label' => '—',
                'rejection_motif' => $motif,
                'last_action' => 'Rejeté : '.$motif,
                'due_on' => null,
            ])->save();
            $this->log($paiement, $actor, 'rejet', $from, PaiementStatus::Rejete->value, $motif);
            $this->informInitiator($paiement, 'rejeté par l’Agent Comptable : '.$motif);

            return $paiement->fresh();
        });
    }

    public function suspendre(Paiement $paiement, User $actor, string $motif): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $motif) {
            $this->assertAgent($actor);
            $this->assertStatus($paiement, [PaiementStatus::ASigner, PaiementStatus::Autorise, PaiementStatus::PayePartiel]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::Suspendu,
                'expected_actor_label' => 'Agent Comptable',
                'last_action' => 'Suspendu : '.$motif,
                'return_motif' => $motif,
            ])->save();
            $this->log($paiement, $actor, 'suspension', $from, PaiementStatus::Suspendu->value, $motif);
            $this->informInitiator($paiement, 'suspendu par l’Agence Comptable : '.$motif);

            return $paiement->fresh();
        });
    }

    /**
     * Lève une suspension : le dossier reprend exactement l’étape qu’il
     * occupait avant d’être suspendu.
     */
    public function leverSuspension(Paiement $paiement, User $actor, string $motif): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $motif) {
            $this->assertAgent($actor);
            $this->assertStatus($paiement, [PaiementStatus::Suspendu]);
            $previous = PaiementStatus::tryFrom((string) $paiement->events()
                ->where('action', 'suspension')
                ->latest('id')
                ->value('from_status')) ?? PaiementStatus::ASigner;
            $paiement->forceFill([
                'status' => $previous,
                'expected_actor_label' => $previous === PaiementStatus::ASigner ? 'Agent Comptable' : 'Comptable · exécution',
                'last_action' => 'Suspension levée : '.$motif,
                'return_motif' => null,
            ])->save();
            $this->log($paiement, $actor, 'levee_suspension', PaiementStatus::Suspendu->value, $previous->value, $motif);
            $this->informInitiator($paiement, 'suspension levée : '.$motif);

            return $paiement->fresh();
        });
    }

    /**
     * Exécution d’un décaissement (PAI-002, PAI-004) : le paiement est verrouillé,
     * le solde est relu sous verrou, la référence bancaire ne peut servir
     * qu’une fois et un rejeu de la même exécution est sans effet.
     */
    /**
     * @param  array{nom: string, chemin: string, sha256: string}|null  $preuveStockee
     */
    public function executer(Paiement $paiement, User $actor, int $montant, string $reference, string $dateValeur, ?UploadedFile $preuve = null, ?array $preuveStockee = null): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $montant, $reference, $dateValeur, $preuve, $preuveStockee) {
            $key = 'PAY-EXEC-'.$paiement->id.'-'.$reference;
            if (PaiementExecution::query()->where('idempotence_key', $key)->exists()) {
                return $paiement->fresh();
            }

            if (! $actor->porte('paiement.executer')) {
                throw ValidationException::withMessages([
                    'action' => 'L’exécution du paiement exige la permission paiement.executer.',
                ]);
            }
            $this->assertStatus($paiement, [PaiementStatus::Autorise, PaiementStatus::PayePartiel]);
            $reste = $paiement->reste();
            if ($montant < 1 || $montant > $reste) {
                throw ValidationException::withMessages(['montant' => 'Le montant exécuté doit rester dans le solde ('.number_format($reste, 0, ',', ' ').' FCFA).']);
            }
            $this->assertReferenceUnused($paiement, $reference);
            $stored = $preuveStockee ?? ($preuve === null ? null : $this->conserverPreuve($preuve));
            if ($stored === null) {
                throw ValidationException::withMessages(['preuve' => 'L’avis bancaire ou l’acquit est obligatoire pour constater le paiement.']);
            }

            PaiementExecution::query()->create([
                'paiement_id' => $paiement->id,
                'rang' => (int) $paiement->executions()->max('rang') + 1,
                'montant' => $montant,
                'reference_reglement' => $reference,
                'mode' => $paiement->mode,
                'date_valeur' => $dateValeur,
                'status' => PaiementExecution::EXECUTEE,
                'idempotence_key' => $key,
                'actor_id' => $actor->id,
                'lot_id' => $paiement->lot_id,
                'preuve_nom' => $stored['nom'],
                'preuve_chemin' => $stored['chemin'],
                'preuve_sha256' => $stored['sha256'],
            ]);

            $paye = $this->paidAmount($paiement);
            $status = $paye >= (int) $paiement->montant ? PaiementStatus::ARapprocher : PaiementStatus::PayePartiel;
            $from = $paiement->status->value;
            $paiement->forceFill([
                'montant_paye' => $paye,
                'reference_reglement' => $reference,
                'date_valeur' => $dateValeur,
                'status' => $status,
                'workflow_step' => $status === PaiementStatus::ARapprocher ? 'rapprochement' : 'comptable',
                'expected_actor_label' => $status === PaiementStatus::ARapprocher ? 'Comptable · rapprochement' : $actor->name,
                'last_action' => 'Exécuté '.$reference,
                'due_on' => $status === PaiementStatus::ARapprocher ? WorkingCalendar::dueIn(5) : WorkingCalendar::dueIn(2),
            ])->save();
            $this->log($paiement, $actor, 'execution', $from, $status->value, null, $reference);
            $this->informInitiator($paiement, $status === PaiementStatus::ARapprocher
                ? 'réglé en totalité : '.$this->fcfa($paye).' FCFA (référence '.$reference.')'
                : 'réglé partiellement : '.$this->fcfa($paye).' FCFA payés sur '.$this->fcfa((int) $paiement->montant).' FCFA (référence '.$reference.')');
            FinancialAudit::record($actor, 'paiement.execution', 'paiement', (string) $paiement->id, ['paye' => $paye - $montant], ['paye' => $paye, 'reference' => $reference, 'montant' => $montant]);
            IntegrationMessage::record($key, 'paiement.execute', 'paiement', (string) $paiement->id, [
                'reference' => $reference,
                'montant' => $montant,
                'mode' => $paiement->mode,
            ]);

            return $paiement->fresh();
        });
    }

    public function rapprocher(Paiement $paiement, User $actor, string $reference): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $reference) {
            $this->assertComptable($actor);
            $this->assertStatus($paiement, [PaiementStatus::ARapprocher]);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'status' => PaiementStatus::Cloture,
                'workflow_step' => 'clos',
                'expected_actor_label' => '—',
                'reconciled_at' => now(),
                'reconciliation_reference' => $reference,
                'last_action' => 'Rapproché '.$reference,
                'due_on' => null,
            ])->save();
            $this->log($paiement, $actor, 'rapprochement', $from, PaiementStatus::Cloture->value, null, $reference);

            return $paiement->fresh();
        });
    }

    /**
     * Rejet bancaire. Avant exécution, l’ordre est simplement refusé. Après
     * exécution, l’exécution visée est neutralisée (jamais effacée) et le
     * reste à payer est rétabli ; le dossier doit être réémis (PAI-004).
     */
    public function rejetBancaire(Paiement $paiement, User $actor, string $motif, ?int $executionId = null): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $motif, $executionId) {
            $this->assertComptable($actor);
            $this->assertStatus($paiement, [PaiementStatus::Autorise, PaiementStatus::PayePartiel, PaiementStatus::ARapprocher]);

            $execution = null;
            if ($paiement->status !== PaiementStatus::Autorise || $executionId !== null) {
                $execution = $paiement->executions()->executees()
                    ->when($executionId !== null, fn ($query) => $query->whereKey($executionId))
                    ->reorder('rang', 'desc')
                    ->first();
                if ($execution === null) {
                    throw ValidationException::withMessages(['execution' => 'Aucune exécution bancaire à rejeter sur ce paiement.']);
                }
                $execution->forceFill([
                    'status' => PaiementExecution::REJETEE,
                    'rejection_motif' => $motif,
                    'rejected_at' => now(),
                    'rejected_by' => $actor->id,
                ])->save();
            }

            $paye = $this->paidAmount($paiement);
            $from = $paiement->status->value;
            $paiement->forceFill([
                'montant_paye' => $paye,
                'status' => PaiementStatus::RejeteBancaire,
                'workflow_step' => 'comptable',
                'expected_actor_label' => 'Comptable · Agence Comptable',
                'bank_rejection' => $motif,
                'last_action' => 'Rejet bancaire : '.$motif,
                'due_on' => WorkingCalendar::dueIn(3),
            ])->save();
            $this->log($paiement, $actor, 'rejet_bancaire', $from, PaiementStatus::RejeteBancaire->value, $motif, $execution?->reference_reglement);
            $this->informInitiator($paiement, 'rejeté par la banque : '.$motif.'. Le règlement sera réémis.');
            if ($execution !== null) {
                FinancialAudit::record($actor, 'paiement.rejet_bancaire', 'paiement', (string) $paiement->id, ['paye' => $paye + (int) $execution->montant], ['paye' => $paye, 'execution' => $execution->reference_reglement], $motif);
            }

            return $paiement->fresh();
        });
    }

    /**
     * Réémission après rejet bancaire : le dossier repart en préparation et
     * doit être de nouveau contrôlé puis autorisé avant tout décaissement.
     */
    public function reemettre(Paiement $paiement, User $actor, string $motif): Paiement
    {
        return TransitionLock::run($paiement, function (Paiement $paiement) use ($actor, $motif) {
            $this->assertComptable($actor);
            $this->assertStatus($paiement, [PaiementStatus::RejeteBancaire]);
            $paiement->forceFill([
                'status' => PaiementStatus::EnPreparation,
                'workflow_step' => 'comptable',
                'expected_actor_label' => $actor->name,
                'lot_id' => null,
                'validated_at' => null,
                'signed_at' => null,
                'signed_by' => null,
                'last_action' => 'Réémission : '.$motif,
                'due_on' => WorkingCalendar::dueIn(2),
            ])->save();
            $this->log($paiement, $actor, 'reemission', PaiementStatus::RejeteBancaire->value, PaiementStatus::EnPreparation->value, $motif);

            return $paiement->fresh();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function ouvrirLot(User $actor, string $libelle, array $ids): PayLot
    {
        if (! $actor->holds('comptable', 'chef_comptable', 'agent_comptable')) {
            throw ValidationException::withMessages(['action' => 'Le lot est réservé à l’Agence Comptable.']);
        }

        return DB::transaction(function () use ($actor, $libelle, $ids) {
            TransitionLock::rows(Paiement::class, $ids);
            $paiements = Paiement::query()->whereIn('id', $ids)->get();
            if ($paiements->count() < 2 || $paiements->contains(fn (Paiement $row) => $row->status !== PaiementStatus::Autorise || $row->mode !== 'virement' || $row->lot_id !== null)) {
                throw ValidationException::withMessages(['paiements' => 'Un lot regroupe au moins deux virements autorisés, non déjà affectés.']);
            }

            $lot = PayLot::query()->create([
                'reference' => app(NumberingService::class)->nextLot((int) now()->year),
                'libelle' => $libelle,
                'status' => 'ouvert',
                'compte_debiteur' => $paiements->first()->compte_ceeac,
                'montant' => (int) $paiements->sum('montant'),
            ]);
            Paiement::query()->whereIn('id', $paiements->pluck('id'))->update(['lot_id' => $lot->id]);
            foreach ($paiements as $paiement) {
                $this->log($paiement, $actor, 'lot', $paiement->status->value, $paiement->status->value, null, $lot->reference);
            }

            return $lot->fresh('paiements');
        });
    }

    public function executerLot(PayLot $lot, User $actor, string $reference, string $dateValeur, UploadedFile $preuve): PayLot
    {
        $this->assertComptable($actor);
        $stored = $this->conserverPreuve($preuve);

        return TransitionLock::run($lot, function (PayLot $lot) use ($actor, $reference, $dateValeur, $stored) {
            if ($lot->status !== 'ouvert') {
                throw ValidationException::withMessages(['action' => 'Ce lot est déjà exécuté.']);
            }
            foreach ($lot->paiements as $paiement) {
                if ($paiement->status === PaiementStatus::Autorise) {
                    $this->executer($paiement, $actor, $paiement->reste(), $reference, $dateValeur, null, $stored);
                }
            }
            $lot->forceFill([
                'status' => 'execute',
                'reference_reglement' => $reference,
                'date_valeur' => $dateValeur,
            ])->save();

            return $lot->fresh('paiements');
        });
    }

    /**
     * Information de l’initiateur sur le sort du règlement : il n’a pas de
     * tâche à cette étape, mais le dossier reste le sien.
     */
    private function informInitiator(Paiement $paiement, string $verb): void
    {
        $initiator = $paiement->ordonnancement?->liquidation?->engagement?->expressionBesoin?->initiator;
        if ($initiator instanceof User && $initiator->account_status === 'actif') {
            $initiator->notify(new PaiementWorkflowNotification($paiement, $verb));
        }
    }

    private function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }

    /**
     * @return array{nom: string, chemin: string, sha256: string}
     */
    private function conserverPreuve(UploadedFile $preuve): array
    {
        $sha = hash_file('sha256', (string) $preuve->getRealPath());
        $path = $preuve->store('preuves/paiements', 'local');

        return [
            'nom' => $preuve->getClientOriginalName(),
            'chemin' => $path,
            'sha256' => $sha !== false ? $sha : hash('sha256', (string) Storage::disk('local')->get($path)),
        ];
    }

    /**
     * @return list<array{point: string, ok: bool, bloquant: bool, detail: string}>
     */
    public function controls(Paiement $paiement): array
    {
        $ordre = $paiement->ordonnancement;
        $liquidation = $ordre?->liquidation;
        $coords = filled($paiement->banque) && filled($paiement->compte) && filled($paiement->titulaire);
        $account = $paiement->bankAccount;
        $expected = app(TiersService::class)->expectedTiers($paiement);
        $bankMode = in_array($paiement->mode, ['virement', 'cheque'], true);
        $accountOk = ! $bankMode || ($account !== null && $account->isUsable() && $expected !== null && $account->tiers_id === $expected->id && $account->numero === $paiement->compte);

        return [
            ['point' => 'Compte validé au référentiel tiers', 'ok' => $accountOk, 'bloquant' => true, 'detail' => ! $bankMode ? 'Règlement en caisse' : ($accountOk ? ($account->tiers?->code.' · '.$account->maskedNumber().' · validé par '.$account->validatedBy?->name) : 'Compte absent, non validé, désactivé ou tiers suspendu')],
            ['point' => 'Ordre de paiement signé', 'ok' => filled($ordre?->signature_reference), 'bloquant' => true, 'detail' => $ordre?->signature_reference ?? 'Signature absente'],
            ['point' => 'Liquidation visée', 'ok' => filled($liquidation?->visa_reference), 'bloquant' => true, 'detail' => $liquidation?->visa_reference ?? 'Visa absent'],
            ['point' => 'Bénéficiaire identifié', 'ok' => filled($liquidation?->fournisseur), 'bloquant' => true, 'detail' => $liquidation?->fournisseur ?? '—'],
            ['point' => 'Coordonnées de paiement', 'ok' => $paiement->mode === 'caisse' || $coords, 'bloquant' => true, 'detail' => $coords ? $paiement->banque.' · '.$paiement->compte : 'À saisir en préparation'],
            ['point' => 'Montant dans le net ordonnancé', 'ok' => (int) $paiement->montant > 0 && (int) $paiement->montant <= (int) $ordre?->montant, 'bloquant' => true, 'detail' => number_format((int) $paiement->montant, 0, ',', ' ').' FCFA'],
            ['point' => 'Paiement non déjà soldé', 'ok' => $paiement->reste() > 0 || $paiement->status?->countsAsPaid() === true, 'bloquant' => true, 'detail' => 'Reste '.number_format($paiement->reste(), 0, ',', ' ').' FCFA'],
            ['point' => 'Mode de paiement autorisé', 'ok' => in_array($paiement->mode, ['virement', 'cheque', 'caisse'], true), 'bloquant' => true, 'detail' => $paiement->mode ?? 'Non choisi'],
            ['point' => 'Plafond de caisse', 'ok' => $paiement->mode !== 'caisse' || (int) $paiement->montant <= $this->plafondCaisse(), 'bloquant' => true, 'detail' => 'Plafond '.number_format($this->plafondCaisse(), 0, ',', ' ').' FCFA'],
            ['point' => 'Compte hors période de vigilance', 'ok' => ! $paiement->compte_modifie, 'bloquant' => false, 'detail' => $paiement->compte_modifie ? 'Compte validé depuis moins de '.TiersBankAccount::VIGILANCE_DAYS.' jours : confirmation par contre-appel recommandée' : 'Compte ancien'],
            ['point' => 'Séparation des fonctions', 'ok' => true, 'bloquant' => false, 'detail' => 'Comptable prépare, Chef Comptable contrôle, Agent Comptable autorise ; l’auteur ou le valideur du compte ne peut pas autoriser'],
        ];
    }

    private function eligibleAccount(Paiement $paiement, ?int $accountId): TiersBankAccount
    {
        $tiers = app(TiersService::class)->expectedTiers($paiement);
        if ($tiers === null) {
            throw ValidationException::withMessages([
                'compte_bancaire_id' => 'Le bénéficiaire n’est rattaché à aucun tiers du référentiel : créez ou rattachez sa fiche tiers avant le paiement.',
            ]);
        }
        if (! $tiers->isActive()) {
            throw ValidationException::withMessages(['compte_bancaire_id' => 'Le tiers '.$tiers->code.' est '.$tiers->status.' : aucun paiement n’est possible.']);
        }
        $account = $accountId !== null ? TiersBankAccount::query()->with('tiers')->find($accountId) : null;
        if ($account === null || $account->tiers_id !== $tiers->id || $account->status !== TiersBankAccount::VALIDE) {
            throw ValidationException::withMessages([
                'compte_bancaire_id' => 'Choisissez un compte validé du tiers '.$tiers->code.' · '.$tiers->raison_sociale.'.',
            ]);
        }

        return $account;
    }

    private function paidAmount(Paiement $paiement): int
    {
        return (int) PaiementExecution::query()
            ->where('paiement_id', $paiement->id)
            ->executees()
            ->sum('montant');
    }

    /**
     * Une référence de règlement déjà exécutée sur un autre paiement est un
     * doublon, sauf si les deux paiements appartiennent au même lot bancaire.
     */
    private function assertReferenceUnused(Paiement $paiement, string $reference): void
    {
        $duplicate = PaiementExecution::query()
            ->executees()
            ->where('reference_reglement', $reference)
            ->where('paiement_id', '!=', $paiement->id)
            ->when($paiement->lot_id !== null, fn ($query) => $query->where(
                fn ($inner) => $inner->whereNull('lot_id')->orWhere('lot_id', '!=', $paiement->lot_id)
            ))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'reference' => 'La référence de règlement '.$reference.' a déjà été exécutée sur un autre paiement.',
            ]);
        }
    }

    private function plafondCaisse(): int
    {
        $value = BusinessRule::query()->where('code', 'plafond_caisse')->where('active', true)->value('value');
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['mode' => 'Le plafond de règlement en caisse n’est pas configuré.']);
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertMode(Paiement $paiement, array $data): void
    {
        $mode = (string) ($data['mode'] ?? '');
        if (! in_array($mode, ['virement', 'cheque', 'caisse'], true)) {
            throw ValidationException::withMessages(['mode' => 'Le mode doit être un virement, un chèque ou un règlement de caisse.']);
        }
        if ($mode === 'caisse' && (int) $paiement->montant > $this->plafondCaisse()) {
            throw ValidationException::withMessages(['mode' => 'Le plafond de caisse est de '.number_format($this->plafondCaisse(), 0, ',', ' ').' FCFA.']);
        }
        if (in_array($mode, ['virement', 'cheque'], true) && (blank($data['banque'] ?? null) || blank($data['compte'] ?? null) || blank($data['titulaire'] ?? null))) {
            throw ValidationException::withMessages(['compte' => 'La banque, le compte et le titulaire sont obligatoires pour un virement ou un chèque.']);
        }
    }

    /**
     * @param  list<PaiementStatus>  $allowed
     */
    private function assertStatus(Paiement $paiement, array $allowed): void
    {
        if (! in_array($paiement->status, $allowed, true)) {
            throw ValidationException::withMessages(['action' => 'Cette action n’est pas disponible au statut '.$paiement->status?->label().'.']);
        }
    }

    private function assertComptable(User $actor): void
    {
        if (! $actor->holds('comptable')) {
            throw ValidationException::withMessages(['action' => 'Cette action est réservée au Comptable.']);
        }
    }

    private function assertChef(User $actor): void
    {
        if (! $actor->holds('chef_comptable')) {
            throw ValidationException::withMessages(['action' => 'Cette action est réservée au Chef Comptable.']);
        }
    }

    private function assertAgent(User $actor): void
    {
        if (! $actor->holds('agent_comptable')) {
            throw ValidationException::withMessages(['action' => 'Cette action est réservée à l’Agent Comptable.']);
        }
    }

    private function log(Paiement $paiement, ?User $actor, string $action, ?string $from, ?string $to, ?string $motif = null, ?string $observations = null): void
    {
        PayEvent::query()->create([
            'paiement_id' => $paiement->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'motif' => $motif,
            'observations' => $observations,
        ]);
    }
}
