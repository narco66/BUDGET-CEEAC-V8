<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'code', 'label', 'categorie', 'valeur', 'unite', 'periode', 'source',
    'justification', 'version', 'remplace_id', 'statut', 'author_id',
])]
class BudgetHypothesis extends Model
{
    protected $table = 'budget_hypotheses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BudgetCampaign::class, 'campaign_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
