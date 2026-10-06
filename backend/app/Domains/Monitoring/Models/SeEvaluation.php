<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeEvaluation extends Model
{
    protected $table = 'se_evaluations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
