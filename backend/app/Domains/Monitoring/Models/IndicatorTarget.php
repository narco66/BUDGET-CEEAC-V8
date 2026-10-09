<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorTarget extends Model
{
    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'indicator_id',
        'monitoring_period_id',
        'value',
    ];

    protected function casts(): array
    {
        return ['value' => 'float'];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MonitoringPeriod::class, 'monitoring_period_id');
    }
}
