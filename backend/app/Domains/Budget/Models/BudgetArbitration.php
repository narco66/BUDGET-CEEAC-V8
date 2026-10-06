<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'dossier_id', 'line_id', 'cible_id', 'montant_demande', 'montant_retenu',
    'decision', 'motif', 'actor_id', 'version_id',
])]
class BudgetArbitration extends Model
{
    protected $table = 'budget_arbitrages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'montant_demande' => 'integer',
            'montant_retenu' => 'integer',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(BudgetDossier::class, 'dossier_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetDossierLine::class, 'line_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
