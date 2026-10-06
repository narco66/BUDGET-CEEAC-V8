<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\OrdDelegation;
use App\Domains\Commitments\Models\OrdEvent;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Notifications\OrdonnancementWorkflowNotification;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use App\Shared\Support\ExerciceGuard;
use App\Shared\Support\NumberingService;
use App\Shared\Support\TransitionLock;
use App\Shared\Support\WorkingCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdonnancementWorkflow
{
    /**
     * @return array{ordonnateur_role: string, ordonnateur_label: string, fondement: string, expected_actor_label: string, seuil: int|null}
     */
    public function authority(int $net): array
    {
        $delegation = OrdDelegation::query()
            ->courante()
            ->where('seuil_max', '>=', $net)
            ->orderBy('seuil_max')
            ->first();

        if ($delegation !== null) {
            $user = User::query()->where('role', 'secretaire_general')->first();

            return [
                'ordonnateur_role' => 'secretaire_general',
                'ordonnateur_label' => ($user?->name ?? $delegation->delegataire).' · '.$delegation->fonction,
                'fondement' => 'Délégation '.$delegation->document.' · ≤ '.number_format($delegation->seuil_max, 0, ',', ' ').' FCFA',
                'expected_actor_label' => $user?->name ?? $delegation->delegataire,
                'seuil' => (int) $delegation->seuil_max,
            ];
        }

        $user = User::query()->where('role', 'ordonnateur')->first();
        $seuil = OrdDelegation::query()->courante()->orderByDesc('seuil_max')->value('seuil_max');

        return [
            'ordonnateur_role' => 'ordonnateur',
            'ordonnateur_label' => ($user?->name ?? 'Président de la Commission').' · Ordonnateur principal',
            'fondement' => $seuil
                ? 'Montant supérieur au seuil de délégation ('.number_format((int) $seuil, 0, ',', ' ').' FCFA)'
                : 'Ordonnateur principal',
            'expected_actor_label' => $user?->name ?? 'Président de la Commission',
            'seuil' => $seuil !== null ? (int) $seuil : null,
        ];
    }

    public function openFromLiquidation(Liquidation $liquidation, string $reference): Ordonnancement
    {
        $authority = $this->authority((int) $liquidation->montant_net);
        $ordonnancement = Ordonnancement::query()->create([
            'reference' => $reference,
            'liquidation_id' => $liquidation->id,
            'montant' => $liquidation->montant_net,
            'status' => OrdonnancementStatus::ASigner,
            'workflow_step' => 'ordonnateur',
            'expected_actor_label' => $authority['expected_actor_label'],
            'ordonnateur_role' => $authority['ordonnateur_role'],
            'ordonnateur_label' => $authority['ordonnateur_label'],
            'fondement' => $authority['fondement'],
            'last_action' => 'Présenté au visa de '.$liquidation->reference,
            'due_on' => WorkingCalendar::dueIn(2),
        ]);
        $this->log($ordonnancement, null, 'generation', null, OrdonnancementStatus::ASigner->value, null, $authority['fondement']);

        return $ordonnancement;
    }

    /**
     * Découpe un ordre encore à signer en deux ordres dont la somme reste le net.
     * L’ordre d’origine conserve la première part ; le solde devient un second ordre.
     */
    public function fractionner(Ordonnancement $ordonnancement, User $actor, int $montant): Ordonnancement
    {
        if (! $actor->holds('directeur_budget') && ! $actor->holds('controleur_financier')) {
            throw ValidationException::withMessages([
                'action' => 'Seul le Directeur du Budget ou le Contrôleur Financier fractionne un ordonnancement.',
            ]);
        }
        if ($ordonnancement->status !== OrdonnancementStatus::ASigner || $ordonnancement->signed_at !== null) {
            throw ValidationException::withMessages([
                'action' => 'Seul un ordre encore à signer peut être fractionné.',
            ]);
        }
        if (($ordonnancement->nature ?? 'normal') !== 'normal') {
            throw ValidationException::withMessages([
                'action' => 'Un ordre rectificatif ou déjà partiel ne se fractionne pas.',
            ]);
        }
        if ($montant < 1 || $montant >= (int) $ordonnancement->montant) {
            throw ValidationException::withMessages([
                'montant' => 'La première part doit être inférieure au montant de l’ordre.',
            ]);
        }

        return DB::transaction(function () use ($ordonnancement, $actor, $montant) {
            $reste = (int) $ordonnancement->montant - $montant;
            $ordonnancement->forceFill([
                'montant' => $montant,
                'nature' => 'partiel',
                'last_action' => 'Ordonnancement partiel',
            ])->save();
            $this->log($ordonnancement, $actor, 'fraction', OrdonnancementStatus::ASigner->value, OrdonnancementStatus::ASigner->value, null, 'Première part '.$montant);

            $year = (int) now()->year;
            $suite = $this->openFromLiquidation($ordonnancement->liquidation, app(NumberingService::class)->nextOrdonnancement($year));
            $authority = $this->authority($reste);
            $suite->forceFill([
                'montant' => $reste,
                'nature' => 'partiel',
                'expected_actor_label' => $authority['expected_actor_label'],
                'ordonnateur_role' => $authority['ordonnateur_role'],
                'ordonnateur_label' => $authority['ordonnateur_label'],
                'fondement' => $authority['fondement'],
                'last_action' => 'Solde de '.$ordonnancement->reference,
            ])->save();

            return $suite->fresh();
        });
    }

    /**
     * Ouvre un ordre correctif sans modifier l’acte déjà signé.
     * Un avoir diminue le net ordonnancé une fois signé ; un complément l’augmente.
     */
    public function openCorrective(Liquidation $liquidation, string $nature, int $amount): Ordonnancement
    {
        $year = (int) ($liquidation->engagement?->expressionBesoin?->exercice()->value('annee') ?? now()->year);
        $ordre = $this->openFromLiquidation($liquidation, app(NumberingService::class)->nextOrdonnancement($year));
        $authority = $this->authority($amount);
        $ordre->forceFill([
            'montant' => $amount,
            'nature' => $nature,
            'expected_actor_label' => $authority['expected_actor_label'],
            'ordonnateur_role' => $authority['ordonnateur_role'],
            'ordonnateur_label' => $authority['ordonnateur_label'],
            'fondement' => $authority['fondement'],
            'last_action' => $nature === 'rectificatif'
                ? 'Avoir à signer sur un ordre déjà émis'
                : 'Complément à signer sur un ordre déjà émis',
        ])->save();

        return $ordre->fresh();
    }

    /**
     * Représente l’ordre après correction de la liquidation : le montant suit
     * le nouveau net visé et l’ordonnateur compétent est recalculé.
     */
    public function present(Ordonnancement $ordonnancement, Liquidation $liquidation): Ordonnancement
    {
        $montant = (int) $liquidation->montant_net;
        $authority = $this->authority($montant);
        $from = $ordonnancement->status?->value;
        $ordonnancement->forceFill([
            'montant' => $montant,
            'status' => OrdonnancementStatus::ASigner,
            'workflow_step' => 'ordonnateur',
            'expected_actor_label' => $authority['expected_actor_label'],
            'ordonnateur_role' => $authority['ordonnateur_role'],
            'ordonnateur_label' => $authority['ordonnateur_label'],
            'fondement' => $authority['fondement'],
            'return_motif' => null,
            'last_action' => 'Représenté après correction de la liquidation',
            'due_on' => WorkingCalendar::dueIn(2),
        ])->save();
        $this->log($ordonnancement, null, 'representation', $from, OrdonnancementStatus::ASigner->value, null, $authority['fondement']);

        return $ordonnancement->fresh();
    }

    /**
     * Signature : l’ordre est verrouillé, la compétence de l’ordonnateur est
     * réévaluée à la date de signature (délégation expirée ou seuil modifié)
     * et le paiement n’est ouvert qu’une fois (ORD-002, ORD-007). La
     * réauthentification du signataire est faite en amont (SignatureVerifier).
     */
    public function sign(Ordonnancement $ordonnancement, User $actor, bool $confirmed): Ordonnancement
    {
        $plafond = $actor->plafondActif();
        if ($plafond !== null && (int) $ordonnancement->montant > $plafond) {
            $this->log($ordonnancement, $actor, 'plafond_depasse', $ordonnancement->status->value, $ordonnancement->status->value, null, 'Montant '.$ordonnancement->montant.' au-delà du plafond '.$plafond);
            throw ValidationException::withMessages([
                'action' => 'Le montant dépasse le plafond de l’habilitation ('.number_format($plafond, 0, ',', ' ').' FCFA).',
            ]);
        }
        $rerouted = null;
        $result = TransitionLock::run($ordonnancement, function (Ordonnancement $locked) use ($actor, $confirmed, &$rerouted) {
            $this->assertOrdonnateur($actor, $locked);
            $authority = $this->authority((int) $locked->montant);
            if ($authority['ordonnateur_role'] !== $locked->ordonnateur_role && $locked->status === OrdonnancementStatus::ASigner) {
                $from = $locked->status->value;
                $locked->forceFill([
                    'expected_actor_label' => $authority['expected_actor_label'],
                    'ordonnateur_role' => $authority['ordonnateur_role'],
                    'ordonnateur_label' => $authority['ordonnateur_label'],
                    'fondement' => $authority['fondement'],
                    'last_action' => 'Réorienté : compétence modifiée à la date de signature',
                ])->save();
                $this->log($locked, $actor, 'reorientation', $from, $from, null, $authority['fondement']);
                $rerouted = $authority['ordonnateur_label'];

                return $locked;
            }

            return $this->performSign($locked, $actor, $confirmed);
        });

        if ($rerouted !== null) {
            throw ValidationException::withMessages([
                'action' => 'Votre compétence n’est plus en vigueur pour ce montant à cette date. Le dossier a été réorienté vers '.$rerouted.'.',
            ]);
        }

        return $result;
    }

    public function sendBack(Ordonnancement $ordonnancement, User $actor, string $motif): Ordonnancement
    {
        return TransitionLock::run($ordonnancement, fn (Ordonnancement $locked) => $this->performSendBack($locked, $actor, $motif));
    }

    public function reject(Ordonnancement $ordonnancement, User $actor, string $motif): Ordonnancement
    {
        return TransitionLock::run($ordonnancement, fn (Ordonnancement $locked) => $this->performReject($locked, $actor, $motif));
    }

    public function reprendre(Ordonnancement $ordonnancement, User $actor): Ordonnancement
    {
        return TransitionLock::run($ordonnancement, fn (Ordonnancement $locked) => $this->performReprendre($locked, $actor));
    }

    private function performSign(Ordonnancement $ordonnancement, User $actor, bool $confirmed): Ordonnancement
    {
        $this->assertOrdonnateur($actor, $ordonnancement);
        if ($ordonnancement->status !== OrdonnancementStatus::ASigner) {
            throw ValidationException::withMessages(['action' => 'Ce dossier n’est pas en attente de signature.']);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmation' => 'La confirmation de l’ordonnancement est obligatoire.']);
        }
        ExerciceGuard::assertOpenForLine($ordonnancement->liquidation?->engagement?->budgetLine);

        return DB::transaction(function () use ($ordonnancement, $actor) {
            $year = (int) now()->year;
            $from = $ordonnancement->status->value;
            $signedAt = now();
            $reference = app(NumberingService::class)->nextSignature($year);
            $visa = $ordonnancement->liquidation?->visa_reference;
            $ordonnancement->forceFill([
                'signature_reference' => $reference,
                'signature_version' => 'v1',
                'empreinte' => hash('sha256', $ordonnancement->reference.'|'.$ordonnancement->montant.'|'.$visa.'|'.$actor->id.'|'.$signedAt->toIso8601String()),
                'signed_at' => $signedAt,
                'signed_by' => $actor->id,
                'status' => OrdonnancementStatus::Signe,
                'last_action' => 'Signé par '.$actor->name,
                'due_on' => null,
            ])->save();
            $this->log($ordonnancement, $actor, 'signature', $from, OrdonnancementStatus::Signe->value, null, 'Signature électronique interne · v1');
            $this->transmit($ordonnancement->fresh(), $actor);

            return $ordonnancement->fresh(['paiement', 'signataire']);
        });
    }

    private function performSendBack(Ordonnancement $ordonnancement, User $actor, string $motif): Ordonnancement
    {
        $this->assertOrdonnateur($actor, $ordonnancement);
        if ($ordonnancement->status !== OrdonnancementStatus::ASigner) {
            throw ValidationException::withMessages(['action' => 'Un dossier déjà signé ne peut pas être retourné.']);
        }

        return DB::transaction(function () use ($ordonnancement, $actor, $motif) {
            $initiator = $ordonnancement->liquidation?->engagement?->expressionBesoin?->initiator;
            $from = $ordonnancement->status->value;
            $ordonnancement->forceFill([
                'status' => OrdonnancementStatus::Retourne,
                'workflow_step' => 'initiateur',
                'expected_actor_label' => $initiator?->name ?? 'Structure initiatrice',
                'return_motif' => $motif,
                'last_action' => 'Retourné : '.$motif,
                'due_on' => WorkingCalendar::dueIn(5),
            ])->save();
            $ordonnancement->liquidation?->forceFill([
                'status' => LiquidationStatus::Retournee,
                'workflow_step' => 'initiateur',
                'expected_actor_label' => $initiator?->name ?? 'Structure initiatrice',
                'return_motif' => $motif,
                'last_action' => 'Retourné par l’ordonnateur : '.$motif,
                'due_on' => WorkingCalendar::dueIn(5),
            ])->save();
            $this->log($ordonnancement, $actor, 'retour', $from, OrdonnancementStatus::Retourne->value, $motif);

            return $ordonnancement->fresh();
        });
    }

    private function performReject(Ordonnancement $ordonnancement, User $actor, string $motif): Ordonnancement
    {
        $this->assertOrdonnateur($actor, $ordonnancement);
        if ($ordonnancement->status !== OrdonnancementStatus::ASigner) {
            throw ValidationException::withMessages(['action' => 'Un dossier déjà signé ne peut pas être rejeté.']);
        }

        return DB::transaction(function () use ($ordonnancement, $actor, $motif) {
            $from = $ordonnancement->status->value;
            $ordonnancement->forceFill([
                'status' => OrdonnancementStatus::Rejete,
                'workflow_step' => 'clos',
                'expected_actor_label' => '—',
                'rejection_motif' => $motif,
                'last_action' => 'Rejeté : '.$motif,
                'due_on' => null,
            ])->save();
            $ordonnancement->liquidation?->forceFill([
                'status' => LiquidationStatus::Rejetee,
                'workflow_step' => 'clos',
                'expected_actor_label' => '—',
                'rejection_motif' => $motif,
                'last_action' => 'Rejetée à l’ordonnancement : '.$motif,
                'due_on' => null,
            ])->save();
            $this->log($ordonnancement, $actor, 'rejet', $from, OrdonnancementStatus::Rejete->value, $motif);
            $this->informInitiator($ordonnancement, 'rejeté par l’ordonnateur : '.$motif);

            return $ordonnancement->fresh();
        });
    }

    private function performReprendre(Ordonnancement $ordonnancement, User $actor): Ordonnancement
    {
        $this->assertOrdonnateur($actor, $ordonnancement);
        if ($ordonnancement->status !== OrdonnancementStatus::TransmissionErreur) {
            throw ValidationException::withMessages(['action' => 'Aucune transmission en erreur à reprendre.']);
        }
        if ($ordonnancement->signature_reference === null) {
            throw ValidationException::withMessages(['action' => 'La signature reste le préalable à la transmission.']);
        }

        return DB::transaction(function () use ($ordonnancement, $actor) {
            $this->transmit($ordonnancement, $actor);

            return $ordonnancement->fresh(['paiement']);
        });
    }

    public function isOrdonnateur(User $actor, Ordonnancement $ordonnancement): bool
    {
        return $actor->holds((string) $ordonnancement->ordonnateur_role)
            && in_array($ordonnancement->status, [OrdonnancementStatus::ASigner, OrdonnancementStatus::TransmissionErreur], true);
    }

    private function transmit(Ordonnancement $ordonnancement, User $actor): void
    {
        $ordonnancement->transmission_attempts = (int) $ordonnancement->transmission_attempts + 1;
        $ordonnancement->idempotence_key ??= 'ORD-TRANSMISSION-'.$ordonnancement->id;

        if ($ordonnancement->fail_next_transmission) {
            $message = 'Délai d’attente dépassé du service de l’Agence Comptable.';
            $this->appendJournal($ordonnancement, 'Échec', $message);
            $ordonnancement->forceFill([
                'fail_next_transmission' => false,
                'status' => OrdonnancementStatus::TransmissionErreur,
                'workflow_step' => 'ordonnateur',
                'transmission_error' => 'Accusé de l’Agence Comptable non reçu. La signature reste valide.',
                'last_action' => 'Transmission en erreur',
            ])->save();
            $this->log($ordonnancement, $actor, 'transmission_erreur', OrdonnancementStatus::Signe->value, OrdonnancementStatus::TransmissionErreur->value, $ordonnancement->transmission_error);
            // L’ordonnateur reprend par sa tâche ; le Directeur du Budget est informé du blocage.
            app(RoleHolders::class)->query('directeur_budget')->get()->each(
                fn (User $user) => $user->notify(new OrdonnancementWorkflowNotification($ordonnancement, 'signé mais non transmis à l’Agence Comptable : reprise attendue'))
            );

            return;
        }

        $paiement = $ordonnancement->paiement;
        if ($paiement === null) {
            $reference = app(NumberingService::class)->nextPaiement((int) now()->year);
            $paiement = app(PaiementWorkflow::class)->openFromOrdonnancement($ordonnancement, $reference);
        }

        $this->appendJournal($ordonnancement, 'Accusé', 'Transmission acceptée · '.$paiement->reference);
        $ordonnancement->forceFill([
            'status' => OrdonnancementStatus::TransformePaiement,
            'workflow_step' => 'clos',
            'expected_actor_label' => $paiement->reference,
            'paiement_reference' => $paiement->reference,
            'transmission_error' => null,
            'last_action' => 'Transmis à l’Agence Comptable · '.$paiement->reference,
        ])->save();
        $this->log($ordonnancement, $actor, 'transmission', OrdonnancementStatus::Signe->value, OrdonnancementStatus::TransformePaiement->value, null, $ordonnancement->idempotence_key);
        $this->informInitiator($ordonnancement, 'signé et transmis à l’Agence Comptable ('.$paiement->reference.')');
    }

    /**
     * Information de l’initiateur, qui n’a pas de tâche à cette étape.
     */
    private function informInitiator(Ordonnancement $ordonnancement, string $verb): void
    {
        $initiator = $ordonnancement->liquidation?->engagement?->expressionBesoin?->initiator;
        if ($initiator instanceof User && $initiator->account_status === 'actif') {
            $initiator->notify(new OrdonnancementWorkflowNotification($ordonnancement, $verb));
        }
    }

    private function appendJournal(Ordonnancement $ordonnancement, string $resultat, string $message): void
    {
        $journal = $ordonnancement->transmission_journal ?? [];
        $journal[] = [
            'numero' => count($journal) + 1,
            'le' => now()->toDateTimeString(),
            'resultat' => $resultat,
            'message' => $message,
        ];
        $ordonnancement->transmission_journal = $journal;
    }

    private function assertOrdonnateur(User $actor, Ordonnancement $ordonnancement): void
    {
        if (! $actor->holds((string) $ordonnancement->ordonnateur_role)) {
            throw ValidationException::withMessages([
                'action' => 'Cette décision est réservée à l’ordonnateur compétent ('.$ordonnancement->ordonnateur_label.').',
            ]);
        }
    }

    private function log(Ordonnancement $ordonnancement, ?User $actor, string $action, ?string $from, ?string $to, ?string $motif = null, ?string $observations = null): void
    {
        OrdEvent::query()->create([
            'ordonnancement_id' => $ordonnancement->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'motif' => $motif,
            'observations' => $observations,
        ]);
    }
}
