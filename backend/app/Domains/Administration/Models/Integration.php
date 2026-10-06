<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'system', 'environment', 'endpoint', 'auth_mode', 'status', 'secret_encrypted', 'last_error', 'last_tested_at'])]
#[Hidden(['secret_encrypted'])]
class Integration extends Model
{
    protected function casts(): array
    {
        return ['last_tested_at' => 'datetime'];
    }
}
