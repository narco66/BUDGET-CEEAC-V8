<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Problème : événement déjà survenu, à la différence d’un risque (description
 * S&E §34). Un risque réalisé est converti en problème.
 */
class SeProblem extends Model
{
    protected $table = 'se_problems';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'reference',
        'pap_enrichment_id',
        'performance_variance_id',
        'se_risk_id',
        'nature',
        'impact',
        'occurred_on',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(SeRisk::class, 'se_risk_id');
    }
}
