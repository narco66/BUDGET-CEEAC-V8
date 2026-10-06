<?php

namespace App\Domains\Budget\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_id', 'forecast_id', 'code', 'label', 'montant'])]
class BudgetVersionForecast extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['montant' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'version_id');
    }
}
