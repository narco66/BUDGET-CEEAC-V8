<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'sigle', 'name', 'kind', 'is_technical', 'version_id', 'description', 'sort_order', 'is_active', 'effective_on'])]
class OrganizationUnit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_technical' => 'boolean',
            'is_active' => 'boolean',
            'effective_on' => 'date',
        ];
    }

    /**
     * @param  Builder<OrganizationUnit>  $query
     * @return Builder<OrganizationUnit>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('sigle');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(OrganizationVersion::class, 'version_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrganizationAssignment::class);
    }

    public function structureLabel(): string
    {
        if ($this->parent) {
            return $this->parent->sigle.' · '.$this->name;
        }

        return $this->sigle.' · '.$this->name;
    }
}
