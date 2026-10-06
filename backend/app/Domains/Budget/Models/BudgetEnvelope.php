<?php

namespace App\Domains\Budget\Models;

use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'campaign_id', 'version', 'organization_unit_id', 'parent_id', 'classification', 'perimetre',
    'montant', 'devise', 'justification', 'date_effet', 'statut', 'author_id',
])]
class BudgetEnvelope extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'montant' => 'integer',
            'date_effet' => 'date',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BudgetCampaign::class, 'campaign_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
