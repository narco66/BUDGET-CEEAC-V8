<?php

namespace App\Domains\Revenues\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'receipt_id', 'kind', 'montant', 'motif', 'author_id'])]
class RevenueAdjustment extends Model
{
    protected function casts(): array
    {
        return ['montant' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RevenueOrder::class, 'order_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
