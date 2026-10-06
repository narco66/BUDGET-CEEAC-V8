<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'label', 'description', 'sensitive', 'active', 'category', 'system'])]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'sensitive' => 'boolean',
            'active' => 'boolean',
            'system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withPivot('level');
    }
}
