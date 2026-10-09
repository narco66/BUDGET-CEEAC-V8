<?php

namespace App\Domains\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Révision motivée du planning d’une activité, soumise à validation. Le
 * planning initial des tâches (baseline) n’est jamais modifié.
 */
class SePlanningRevision extends Model
{
    protected $table = 'se_planning_revisions';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'pap_enrichment_id',
        'version',
        'status',
        'motif',
        'tasks',
        'proposed_by',
        'decided_by',
        'decided_at',
        'decision_motif',
    ];

    protected function casts(): array
    {
        return ['tasks' => 'array', 'decided_at' => 'datetime'];
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
