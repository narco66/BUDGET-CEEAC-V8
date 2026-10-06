<?php

namespace App\Domains\Monitoring\Models;

use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceVariance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'physical_rate' => 'float',
            'financial_rate' => 'float',
            'gap' => 'float',
            'due_on' => 'date',
            'explanation_requested_at' => 'datetime',
            'explanation_received_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'interpretations' => 'array',
            'cause_categories' => 'array',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PapEnrichment::class, 'pap_enrichment_id');
    }

    public function explainedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'explained_by');
    }
}
