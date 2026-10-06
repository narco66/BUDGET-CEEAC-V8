<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeReferential extends Model
{
    protected $table = 'se_referentials';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'active' => 'boolean',
            'effective_on' => 'date',
        ];
    }
}
