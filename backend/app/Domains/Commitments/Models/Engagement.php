<?php

namespace App\Domains\Commitments\Models;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Planning\Models\GarVersion;
use App\Domains\Procurement\Models\Marche;
use App\Domains\Suppliers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'expression_besoin_id',
    'budget_line_id',
    'montant',
    'status',
    'workflow_step',
    'expected_actor_label',
    'beneficiary_name',
    'beneficiary_rccm',
    'beneficiary_nif',
    'last_action',
    'due_on',
    'reserved_at',
    'visa_reference',
    'vised_at',
    'liquidation_reference',
    'return_motif',
    'rejection_motif',
    'montant_degage',
    'cancelled_at',
    'cancellation_motif',
    'tiers_id',
    'gar_version_id',
    'nature',
    'parent_engagement_id',
    'avenant_motif',
])]
class Engagement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'montant_degage' => 'integer',
            'status' => EngagementStatus::class,
            'due_on' => 'date',
            'reserved_at' => 'datetime',
            'vised_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Engagé net : montant de l’acte diminué des dégagements. C’est ce montant
     * qui consomme le crédit et plafonne les liquidations.
     */
    public function montantNet(): int
    {
        return max(0, (int) $this->montant - (int) $this->montant_degage);
    }

    public function parentEngagement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_engagement_id');
    }

    public function suites(): HasMany
    {
        return $this->hasMany(self::class, 'parent_engagement_id');
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function garVersion(): BelongsTo
    {
        return $this->belongsTo(GarVersion::class);
    }

    public function marche(): HasOne
    {
        return $this->hasOne(Marche::class);
    }

    public function degagements(): HasMany
    {
        return $this->hasMany(EngagementDegagement::class)->latest('id');
    }

    public function expressionBesoin(): BelongsTo
    {
        return $this->belongsTo(ExpressionBesoin::class);
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EngEvent::class)->latest();
    }

    public function liquidations(): HasMany
    {
        return $this->hasMany(Liquidation::class)->orderBy('id');
    }

    /**
     * Liquidation ouverte au visa. Les liquidations ultérieures restent
     * accessibles par {@see liquidations()}.
     */
    public function liquidation(): HasOne
    {
        return $this->hasOne(Liquidation::class)->oldestOfMany();
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [
            EngagementStatus::Vise,
            EngagementStatus::TransformeLiquidation,
            EngagementStatus::Rejete,
            EngagementStatus::Annule,
        ], true);
    }
}
