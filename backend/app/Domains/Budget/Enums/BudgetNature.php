<?php

namespace App\Domains\Budget\Enums;

enum BudgetNature: string
{
    case Pap = 'pap';
    case HorsPap = 'hors_pap';

    public function label(): string
    {
        return match ($this) {
            self::Pap => 'PAP',
            self::HorsPap => 'Hors PAP',
        };
    }
}
