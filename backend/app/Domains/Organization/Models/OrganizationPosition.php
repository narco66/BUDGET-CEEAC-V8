<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'label', 'description', 'rank', 'compatible_kind', 'parent_position_id', 'is_active'])]
class OrganizationPosition extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_position_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_position_id');
    }
}
