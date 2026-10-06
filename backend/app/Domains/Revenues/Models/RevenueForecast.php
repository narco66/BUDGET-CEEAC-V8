<?php

namespace App\Domains\Revenues\Models;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'exercice_id', 'category_id', 'organization_unit_id', 'code', 'label', 'description',
    'montant', 'source_label', 'periode', 'date_prevue', 'observations', 'statut',
    'author_id', 'validated_by',
])]
class RevenueForecast extends Model
{
    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'date_prevue' => 'date',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RevenueCategory::class, 'category_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
