<?php

namespace App\Domains\Administration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'delegant_id', 'delegataire_id', 'fonction', 'perimetre', 'starts_on', 'ends_on',
    'motif', 'document', 'status', 'approved_by',
])]
class AdminDelegation extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function delegant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegant_id');
    }

    public function delegataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegataire_id');
    }

    public function effective(): bool
    {
        return $this->status === 'active'
            && $this->starts_on?->lte(now()->startOfDay())
            && $this->ends_on?->gte(now()->startOfDay());
    }
}
