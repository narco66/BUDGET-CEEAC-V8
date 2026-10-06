<?php

namespace App\Domains\PAP\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pap_enrichment_id', 'position', 'label', 'proposed', 'validated',
    'weight', 'progress_percent', 'starts_on', 'ends_on', 'actual_start', 'actual_end', 'depends_on_id',
    'code', 'unit', 'planned_quantity', 'responsible_label', 'baseline_starts_on', 'baseline_ends_on',
])]
class PapTask extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed' => 'boolean',
            'validated' => 'boolean',
            'weight' => 'integer',
            'progress_percent' => 'float',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
            'planned_quantity' => 'float',
            'baseline_starts_on' => 'date',
            'baseline_ends_on' => 'date',
        ];
    }

    public function enrichment(): BelongsTo
    {
        return $this->belongsTo(PapEnrichment::class, 'pap_enrichment_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }
}
