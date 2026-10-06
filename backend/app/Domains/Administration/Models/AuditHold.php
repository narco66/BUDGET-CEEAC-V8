<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scope', 'object_type', 'object_id', 'motif', 'created_by', 'lifted_at', 'lifted_by'])]
class AuditHold extends Model
{
    protected function casts(): array
    {
        return [
            'lifted_at' => 'datetime',
        ];
    }
}
