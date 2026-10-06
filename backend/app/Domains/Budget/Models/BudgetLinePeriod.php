<?php

namespace App\Domains\Budget\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['line_id', 'periode', 'montant'])]
class BudgetLinePeriod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['montant' => 'integer'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetDossierLine::class, 'line_id');
    }
}
