<?php

namespace App\Domains\Budget\Models;

use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'campaign_id', 'organization_unit_id', 'titre', 'description', 'justification',
    'responsable_id', 'version', 'statut', 'observations', 'retour_motif', 'author_id',
    'duplicate_of_id', 'submitted_at',
])]
class BudgetDossier extends Model
{
    public const EDITER = ['brouillon', 'retourne'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'submitted_at' => 'datetime',
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

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetDossierLine::class, 'dossier_id');
    }

    public function arbitrages(): HasMany
    {
        return $this->hasMany(BudgetArbitration::class, 'dossier_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(BudgetPiece::class, 'dossier_id');
    }
}
