<?php

namespace App\Domains\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'fingerprint', 'module', 'entity_type', 'entity_id', 'dossier_reference', 'subject',
    'action', 'step', 'assigned_role', 'assigned_user_id', 'organization_unit_id', 'priority', 'status',
    'amount', 'objet', 'demandeur', 'structure', 'exercice_year', 'lien', 'assigned_at', 'started_at', 'started_by', 'due_on',
    'completed_at', 'completion_action', 'reminder_level', 'escalation_level',
])]
class WorkflowTask extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'exercice_year' => 'integer',
            'reminder_level' => 'integer',
            'escalation_level' => 'integer',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function preneur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkflowTaskComment::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'terminee';
    }

    public function isLate(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(today());
    }
}
