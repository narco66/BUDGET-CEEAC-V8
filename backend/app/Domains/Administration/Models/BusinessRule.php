<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'label', 'value', 'unit', 'active'])]
class BusinessRule extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
