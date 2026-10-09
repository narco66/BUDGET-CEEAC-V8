<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeReferential extends Model
{
    protected $table = 'se_referentials';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'kind',
        'code',
        'label',
        'weight',
        'formula_version',
        'effective_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'active' => 'boolean',
            'effective_on' => 'date',
        ];
    }
}
