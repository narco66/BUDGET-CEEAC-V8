<?php

namespace App\Domains\Budget\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'ordre', 'label', 'description', 'debut', 'echeance', 'acteurs',
    'structures', 'prerequis', 'livrables', 'verrouillee', 'alerte_echeance_at',
])]
class BudgetCampaignStep extends Model
{
    protected $table = 'budget_campaign_steps';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'debut' => 'date',
            'echeance' => 'date',
            'verrouillee' => 'boolean',
            'alerte_echeance_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BudgetCampaign::class, 'campaign_id');
    }
}
