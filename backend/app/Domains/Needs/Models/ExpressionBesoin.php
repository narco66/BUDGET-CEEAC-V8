<?php

namespace App\Domains\Needs\Models;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'exercice_id',
    'organization_unit_id',
    'initiator_id',
    'budget_line_id',
    'nature',
    'objet',
    'contexte',
    'justification',
    'urgence',
    'priorite',
    'resultats_attendus',
    'status',
    'workflow_step',
    'expected_actor_label',
    'due_on',
    'montant',
    'submitted_at',
    'approved_at',
    'returned_at',
    'rejected_at',
    'return_motif',
    'rejection_motif',
])]
class ExpressionBesoin extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => BudgetNature::class,
            'status' => EbStatus::class,
            'due_on' => 'date',
            'montant' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EbLine::class)->orderBy('position');
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(EbImputation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EbDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EbEvent::class)->orderBy('id');
    }

    public function engagement(): HasOne
    {
        return $this->hasOne(Engagement::class)->oldestOfMany();
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [EbStatus::Brouillon, EbStatus::Retournee, EbStatus::ACorriger], true);
    }
}
