<?php

namespace App\Domains\Revenues\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'receipt_id', 'nom', 'chemin', 'sha256', 'type_piece', 'uploaded_by'])]
class RevenueDocument extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
