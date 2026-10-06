<?php

namespace App\Domains\Budget\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\CreditMovement;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Integration\IntegrationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreditMovementService
{
    /**
     * @var list<string>
     */
    public const KINDS = ['ouverture', 'report', 'annulation', 'gel', 'degel', 'virement'];

    /**
     * @return list<CreditMovement>
     */
    public function record(BudgetLine $line, User $actor, string $kind, int $amount, string $motif, string $acte, ?int $destinationId = null): array
    {
        if (! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'Seul le Directeur du Budget enregistre un mouvement de crédit.']);
        }
        if (! in_array($kind, self::KINDS, true) || $amount < 1 || blank($motif) || blank($acte)) {
            throw ValidationException::withMessages(['mouvement' => 'Le mouvement exige un type, un montant, un motif et un acte.']);
        }

        return DB::transaction(function () use ($line, $actor, $kind, $amount, $motif, $acte, $destinationId) {
            $locked = BudgetLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $group = (string) Str::uuid();

            if ($kind === 'virement') {
                $destination = BudgetLine::query()->whereKey($destinationId)->lockForUpdate()->first();
                if ($destination === null || $destination->id === $locked->id) {
                    throw ValidationException::withMessages(['destination_id' => 'Le virement exige une autre ligne de destination.']);
                }
                if ($locked->disponible() < $amount) {
                    throw ValidationException::withMessages(['montant' => 'Le disponible de la ligne d’origine est insuffisant.']);
                }

                $out = $this->store($locked, $actor, 'transfert_sortant', $amount, $motif, $acte, $group, $destination->id);
                $in = $this->store($destination, $actor, 'transfert_entrant', $amount, $motif, $acte, $group, $locked->id);

                return [$out, $in];
            }

            if ($kind === 'degel' && $locked->montantGele() < $amount) {
                throw ValidationException::withMessages(['montant' => 'Le dégel dépasse le montant gelé.']);
            }
            if (in_array($kind, ['annulation', 'gel'], true) && $locked->disponible() < $amount) {
                throw ValidationException::withMessages(['montant' => 'Ce mouvement dépasserait le disponible.']);
            }

            return [$this->store($locked, $actor, $kind, $amount, $motif, $acte, $group, null)];
        });
    }

    private function store(BudgetLine $line, User $actor, string $kind, int $amount, string $motif, string $acte, string $group, ?int $counterpartId): CreditMovement
    {
        $movement = CreditMovement::query()->create([
            'budget_line_id' => $line->id,
            'counterpart_line_id' => $counterpartId,
            'kind' => $kind,
            'amount' => $amount,
            'motif' => $motif,
            'acte' => $acte,
            'group_key' => $group,
            'actor_id' => $actor->id,
        ]);
        FinancialAudit::record($actor, 'credit.mouvement', 'budget_line', (string) $line->id, null, ['kind' => $kind, 'montant' => $amount, 'acte' => $acte], $motif);
        IntegrationMessage::record('CREDIT-'.$movement->id, 'credit.mouvement', 'credit_movement', (string) $movement->id, ['ligne' => $line->code, 'kind' => $kind, 'montant' => $amount]);

        return $movement;
    }
}
