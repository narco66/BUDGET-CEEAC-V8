<?php

namespace App\Shared\Support;

use App\Domains\Budget\Models\BudgetLine;
use Illuminate\Validation\ValidationException;

class BudgetAvailabilityService
{
    public function assertCanImpute(BudgetLine $line, int $amount, ?int $exceptExpressionBesoinId = null): void
    {
        if ($amount > $line->disponible($exceptExpressionBesoinId)) {
            throw ValidationException::withMessages([
                'credit' => 'Le crédit disponible de la ligne '.$line->code.' est insuffisant.',
            ]);
        }
    }
}
