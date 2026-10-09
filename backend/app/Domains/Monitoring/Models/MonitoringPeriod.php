<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringPeriod extends Model
{
    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'exercice_year',
        'code',
        'label',
        'frequency',
        'opens_on',
        'closes_on',
        'status',
        'consolidated_at',
        'consolidated_by',
    ];

    protected function casts(): array
    {
        return ['opens_on' => 'date', 'closes_on' => 'date'];
    }
}
