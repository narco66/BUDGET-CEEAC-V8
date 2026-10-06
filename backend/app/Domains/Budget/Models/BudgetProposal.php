<?php

namespace App\Domains\Budget\Models;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'exercice_id', 'organization_unit_id', 'code', 'label', 'nature',
    'montant_propose', 'statut', 'author_id', 'budget_line_id',
])]
class BudgetProposal extends Model
{
    protected function casts(): array
    {
        return [
            'nature' => BudgetNature::class,
            'montant_propose' => 'integer',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }
}
