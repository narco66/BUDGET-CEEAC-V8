<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Problème : événement déjà survenu, à la différence d’un risque (description
 * S&E §34). Un risque réalisé est converti en problème.
 */
class SeProblem extends Model
{
    protected $table = 'se_problems';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(SeRisk::class, 'se_risk_id');
    }
}
