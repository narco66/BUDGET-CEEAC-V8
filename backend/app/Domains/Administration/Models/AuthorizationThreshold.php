<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'operation', 'min_amount', 'max_amount', 'actor_role', 'starts_on', 'ends_on', 'document', 'active'])]
class AuthorizationThreshold extends Model
{
    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }
}
