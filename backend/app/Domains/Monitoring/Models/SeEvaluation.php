<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeEvaluation extends Model
{
    protected $table = 'se_evaluations';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'reference',
        'subject',
        'scope',
        'monitoring_period_id',
        'pap_enrichment_id',
        'type',
        'evaluator_role',
        'criteria',
        'conclusions',
        'status',
    ];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
