<?php

namespace App\Domains\Monitoring\Models;

use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalAchievement extends Model
{
    use ProtectsValidatedValues;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'planned' => 'float',
            'progress_percent' => 'float',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'superseded_at' => 'datetime',
            'responsible_validated_at' => 'datetime',
            'consolidated_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PapEnrichment::class, 'pap_enrichment_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PapTask::class, 'pap_task_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_id');
    }
}
