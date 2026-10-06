<?php

namespace App\Domains\Commitments\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ordonnancement_id',
    'actor_id',
    'action',
    'from_status',
    'to_status',
    'motif',
    'observations',
])]
class OrdEvent extends Model
{
    use AppendOnly;

    public function ordonnancement(): BelongsTo
    {
        return $this->belongsTo(Ordonnancement::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
