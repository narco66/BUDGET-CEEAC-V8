<?php

namespace App\Domains\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorMeasurement extends Model
{
    use ProtectsValidatedValues;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'attainment_rate' => 'float',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'superseded_at' => 'datetime',
            'responsible_validated_at' => 'datetime',
            'consolidated_at' => 'datetime',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MonitoringPeriod::class, 'monitoring_period_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
