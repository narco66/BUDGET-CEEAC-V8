<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'code', 'label', 'categorie', 'instructions', 'periode', 'source',
    'justification', 'version', 'remplace_id', 'statut', 'author_id',
])]
class BudgetOrientation extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BudgetCampaign::class, 'campaign_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
