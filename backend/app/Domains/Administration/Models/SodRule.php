<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role_a', 'role_b', 'blocking', 'label', 'active', 'justification'])]
class SodRule extends Model
{
    protected function casts(): array
    {
        return [
            'blocking' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
