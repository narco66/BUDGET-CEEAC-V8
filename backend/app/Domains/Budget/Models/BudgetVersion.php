<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'campaign_id', 'numero', 'libelle', 'statut', 'snapshot', 'author_id',
    'decided_by', 'decided_at', 'motif', 'transmise_at',
])]
class BudgetVersion extends Model
{
    public const FIGEES = ['validee', 'adoptee', 'publiee'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'snapshot' => 'array',
            'decided_at' => 'datetime',
            'transmise_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BudgetCampaign::class, 'campaign_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(BudgetVersionForecast::class, 'version_id');
    }
}
