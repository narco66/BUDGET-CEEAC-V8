<?php

namespace App\Domains\Budget\Models;

use App\Domains\Planning\Models\GarVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['annee', 'statut', 'date_debut', 'date_fin'])]
class Exercice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->statut, ['ouvert', 'executoire'], true);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function garVersion(): BelongsTo
    {
        return $this->belongsTo(GarVersion::class, 'gar_version_id');
    }
}
