<?php

namespace App\Domains\Budget\Models;

use App\Domains\Revenues\Models\RevenueCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['line_id', 'revenue_category_id', 'source', 'montant'])]
class BudgetLineFunding extends Model
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(RevenueCategory::class, 'revenue_category_id');
    }
}
