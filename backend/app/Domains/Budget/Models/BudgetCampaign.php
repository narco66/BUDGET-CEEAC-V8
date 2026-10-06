<?php

namespace App\Domains\Budget\Models;

use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'label', 'exercice_id', 'description', 'perimetre', 'date_ouverture', 'date_cloture',
    'devise', 'responsable_id', 'version_cadrage', 'statut', 'author_id',
])]
class BudgetCampaign extends Model
{
    public const OUVERTES = ['brouillon', 'ouverte', 'suspendue'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_ouverture' => 'date',
            'date_cloture' => 'date',
            'version_cadrage' => 'integer',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationUnit::class, 'budget_campaign_units', 'campaign_id', 'organization_unit_id')->withTimestamps();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(BudgetCampaignStep::class, 'campaign_id')->orderBy('ordre');
    }

    public function hypotheses(): HasMany
    {
        return $this->hasMany(BudgetHypothesis::class, 'campaign_id');
    }

    public function orientations(): HasMany
    {
        return $this->hasMany(BudgetOrientation::class, 'campaign_id');
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(BudgetEnvelope::class, 'campaign_id');
    }

    public function dossiers(): HasMany
    {
        return $this->hasMany(BudgetDossier::class, 'campaign_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class, 'campaign_id')->orderBy('numero');
    }
}
