<?php

namespace App\Domains\Needs\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'expression_besoin_id',
    'actor_id',
    'action',
    'from_status',
    'to_status',
    'motif',
    'observations',
    'fields',
    'payload',
])]
class EbEvent extends Model
{
    use AppendOnly;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'payload' => 'array',
        ];
    }

    public function expressionBesoin(): BelongsTo
    {
        return $this->belongsTo(ExpressionBesoin::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
