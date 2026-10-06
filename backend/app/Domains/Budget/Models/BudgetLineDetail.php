<?php

namespace App\Domains\Budget\Models;

use App\Domains\Planning\Models\GarNode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'line_id', 'designation', 'gar_node_id', 'quantite', 'unite', 'cout_unitaire',
    'montant', 'justification', 'hypothese_id',
])]
class BudgetLineDetail extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'cout_unitaire' => 'integer',
            'montant' => 'integer',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetDossierLine::class, 'line_id');
    }

    public function garNode(): BelongsTo
    {
        return $this->belongsTo(GarNode::class);
    }

    public function hypothesis(): BelongsTo
    {
        return $this->belongsTo(BudgetHypothesis::class, 'hypothese_id');
    }
}
