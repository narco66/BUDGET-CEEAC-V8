<?php

namespace App\Domains\Commitments\Models;

use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'engagement_id',
    'montant',
    'status',
    'workflow_step',
    'expected_actor_label',
    'fournisseur',
    'service_fait_at',
    'certified_by',
    'service_fait_reserves',
    'bon_livraison',
    'nature_prestation',
    'lieu_reception',
    'service_lignes',
    'montant_accepte',
    'invoice_number',
    'invoice_date',
    'invoice_due',
    'montant_ht',
    'taxes',
    'montant_ttc',
    'montant_brut',
    'retenue_garantie',
    'penalite',
    'montant_net',
    'doublon',
    'last_action',
    'due_on',
    'visa_reference',
    'vised_at',
    'ordonnancement_reference',
    'return_motif',
    'rejection_motif',
])]
class Liquidation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'status' => LiquidationStatus::class,
            'service_fait_at' => 'datetime',
            'service_lignes' => 'array',
            'invoice_due' => 'date',
            'montant_accepte' => 'integer',
            'invoice_date' => 'date',
            'montant_ht' => 'integer',
            'taxes' => 'integer',
            'montant_ttc' => 'integer',
            'montant_brut' => 'integer',
            'retenue_garantie' => 'integer',
            'penalite' => 'integer',
            'montant_net' => 'integer',
            'doublon' => 'boolean',
            'due_on' => 'date',
            'vised_at' => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LiqEvent::class)->latest();
    }

    public function rectifications(): HasMany
    {
        return $this->hasMany(LiquidationRectification::class);
    }

    public function ordonnancement(): HasOne
    {
        return $this->hasOne(Ordonnancement::class)->oldestOfMany();
    }

    public function ordonnancements(): HasMany
    {
        return $this->hasMany(Ordonnancement::class)->orderBy('id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [
            LiquidationStatus::Visee,
            LiquidationStatus::TransformeeOrdonnancement,
            LiquidationStatus::Rejetee,
            LiquidationStatus::Annulee,
        ], true);
    }

    public function serviceFaitLabel(): string
    {
        if ($this->service_fait_at === null) {
            return 'En attente';
        }

        return filled($this->service_fait_reserves) ? 'Certifié · réserve' : 'Certifié';
    }
}
