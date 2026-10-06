<?php

namespace App\Domains\Administration\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'occurred_at', 'actor_id', 'actor_type', 'actor_name', 'role', 'habilitation',
    'organization_unit_id', 'exercise_year', 'module', 'action', 'object_type', 'object_id',
    'entity_reference', 'before', 'after', 'changed_fields', 'motif', 'result', 'ip',
    'correlation_id', 'causation_id', 'request_id', 'sensitivity', 'channel', 'user_agent',
    'source_table', 'source_id', 'context',
])]
class AuditEvent extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'changed_fields' => 'array',
            'habilitation' => 'array',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'exercise_year' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
