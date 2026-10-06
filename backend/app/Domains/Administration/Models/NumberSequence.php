<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'prefix', 'padding', 'separator', 'last_value', 'exercise_year', 'reset_policy'])]
class NumberSequence extends Model
{
    protected function casts(): array
    {
        return ['last_value' => 'integer'];
    }
}
