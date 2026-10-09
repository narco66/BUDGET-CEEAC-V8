<?php

namespace App\Domains\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorMeasurement extends Model
{
    use ProtectsValidatedValues;

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'indicator_id',
        'monitoring_period_id',
        'value',
        'status',
        'version',
        'formula_version',
        'supersedes_id',
        'superseded_at',
        'attainment_rate',
        'comment',
        'source',
        'exception_motif',
        'author_id',
        'validator_id',
        'submitted_at',
        'validated_at',
        'rejection_motif',
        'numerator',
        'denominator',
        'justification',
        'responsible_validator_id',
        'responsible_validated_at',
        'consolidated_by',
        'consolidated_at',
    ];

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
