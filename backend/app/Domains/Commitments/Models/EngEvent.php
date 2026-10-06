<?php

namespace App\Domains\Commitments\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'engagement_id',
    'actor_id',
    'action',
    'from_status',
    'to_status',
    'motif',
    'observations',
    'fields',
])]
class EngEvent extends Model
{
    use AppendOnly;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
