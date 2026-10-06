<?php

namespace App\Domains\Commitments\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'liquidation_id',
    'actor_id',
    'action',
    'from_status',
    'to_status',
    'motif',
    'observations',
])]
class LiqEvent extends Model
{
    use AppendOnly;

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
