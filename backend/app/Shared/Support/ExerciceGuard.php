<?php

namespace App\Shared\Support;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Services\PeriodeBudgetaireService;
use Illuminate\Validation\ValidationException;

/**
 * Interdit toute écriture financière sur un exercice clos ou non ouvert
 * (CDC §23 et §63). La réouverture passe par l’administration, avec motif
 * et audit.
 */
final class ExerciceGuard
{
    public static function assertOpenForLine(?BudgetLine $line): void
    {
        self::assertOpen($line?->exercice()->first());
    }

    public static function assertOpen(?Exercice $exercice): void
    {
        if ($exercice === null || ! $exercice->isOpen()) {
            throw ValidationException::withMessages([
                'exercice' => 'L’exercice '.($exercice?->annee ?? '').' n’est pas ouvert : aucune opération financière n’est possible.',
            ]);
        }
        app(PeriodeBudgetaireService::class)->assertDateOuverte($exercice, now());
    }
}
