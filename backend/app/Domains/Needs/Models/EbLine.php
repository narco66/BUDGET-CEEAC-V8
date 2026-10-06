<?php

namespace App\Domains\Needs\Models;

use App\Domains\PAP\Models\PapTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'expression_besoin_id',
    'pap_task_id',
    'position',
    'designation',
    'description',
    'quantite',
    'unite',
    'prix_unitaire',
    'montant',
    'beneficiaire',
    'lieu',
    'periode',
    'observation',
])]
class EbLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'prix_unitaire' => 'integer',
            'montant' => 'integer',
        ];
    }

    public function expressionBesoin(): BelongsTo
    {
        return $this->belongsTo(ExpressionBesoin::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PapTask::class, 'pap_task_id');
    }
}
