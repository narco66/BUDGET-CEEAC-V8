<?php

namespace App\Domains\Revenues\Models;

use App\Domains\Budget\Models\Exercice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['exercice_id', 'member_state_id', 'quote_part', 'montant_attendu', 'echeance', 'observations', 'order_id'])]
class RevenueContribution extends Model
{
    protected function casts(): array
    {
        return [
            'quote_part' => 'integer',
            'montant_attendu' => 'integer',
            'echeance' => 'date',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function memberState(): BelongsTo
    {
        return $this->belongsTo(MemberState::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RevenueOrder::class, 'order_id');
    }
}
