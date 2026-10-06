<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['budget_line_id', 'counterpart_line_id', 'kind', 'amount', 'motif', 'acte', 'group_key', 'actor_id'])]
class CreditMovement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'counterpart_line_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
