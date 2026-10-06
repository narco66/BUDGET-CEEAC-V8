<?php

namespace App\Domains\Revenues\Models;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'exercice_id', 'category_id', 'forecast_id', 'organization_unit_id',
    'debtor_type', 'tiers_id', 'member_state_id', 'debtor_label', 'montant', 'devise',
    'echeance', 'motif', 'description', 'observations', 'statut', 'montant_encaisse',
    'created_by', 'verified_by', 'validated_by',
])]
class RevenueOrder extends Model
{
    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'montant_encaisse' => 'integer',
            'echeance' => 'date',
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

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(RevenueForecast::class, 'forecast_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function memberState(): BelongsTo
    {
        return $this->belongsTo(MemberState::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class, 'order_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(RevenueReminder::class, 'order_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(RevenueAdjustment::class, 'order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RevenueEvent::class, 'order_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RevenueDocument::class, 'order_id');
    }

    public function solde(): int
    {
        return max(0, (int) $this->montant - (int) $this->montant_encaisse);
    }

    public function recouvrable(): bool
    {
        return in_array($this->statut, ['pris_en_charge', 'partiellement_encaisse'], true);
    }
}
