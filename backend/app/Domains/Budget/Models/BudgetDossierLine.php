<?php

namespace App\Domains\Budget\Models;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Planning\Models\GarNode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'dossier_id', 'classification', 'code', 'nature', 'label', 'description', 'justification',
    'quantite', 'unite', 'cout_unitaire', 'montant', 'montant_retenu', 'mode', 'gar_node_id',
    'periode', 'observations',
])]
class BudgetDossierLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => BudgetNature::class,
            'quantite' => 'decimal:2',
            'cout_unitaire' => 'integer',
            'montant' => 'integer',
            'montant_retenu' => 'integer',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(BudgetDossier::class, 'dossier_id');
    }

    public function garNode(): BelongsTo
    {
        return $this->belongsTo(GarNode::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(BudgetLineDetail::class, 'line_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetLinePeriod::class, 'line_id');
    }

    public function fundings(): HasMany
    {
        return $this->hasMany(BudgetLineFunding::class, 'line_id');
    }

    public function retenu(): int
    {
        return $this->montant_retenu ?? (int) $this->montant;
    }
}
