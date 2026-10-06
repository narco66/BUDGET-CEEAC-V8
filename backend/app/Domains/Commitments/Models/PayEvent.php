<?php

namespace App\Domains\Commitments\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'paiement_id', 'actor_id', 'action', 'from_status', 'to_status', 'motif', 'observations',
])]
class PayEvent extends Model
{
    use AppendOnly;

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
