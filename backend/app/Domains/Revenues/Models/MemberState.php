<?php

namespace App\Domains\Revenues\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'nom', 'active'])]
class MemberState extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
