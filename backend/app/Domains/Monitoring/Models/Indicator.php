<?php

namespace App\Domains\Monitoring\Models;

use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Indicator extends Model
{
    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'pap_enrichment_id',
        'code',
        'label',
        'description',
        'type',
        'gar_level',
        'unit',
        'direction',
        'aggregation',
        'formula_version',
        'baseline_value',
        'baseline_on',
        'source',
        'responsible_role',
        'status',
        'weight',
        'formula',
        'frequency',
        'numerator_label',
        'denominator_label',
        'responsible_user_id',
    ];

    protected function casts(): array
    {
        return ['baseline_value' => 'float', 'formula_version' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PapEnrichment::class, 'pap_enrichment_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(IndicatorTarget::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(IndicatorMeasurement::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
