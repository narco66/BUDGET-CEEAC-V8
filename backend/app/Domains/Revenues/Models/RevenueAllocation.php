<?php

namespace App\Domains\Revenues\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_id', 'order_id', 'montant'])]
class RevenueAllocation extends Model
{
    protected function casts(): array
    {
        return ['montant' => 'integer'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(RevenueReceipt::class, 'receipt_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RevenueOrder::class, 'order_id');
    }
}
