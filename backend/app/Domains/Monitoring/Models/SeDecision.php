<?php

namespace App\Domains\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision de revue de performance (description S&E §88) : elle devient une
 * action suivie (responsable, échéance, priorité, statut).
 */
class SeDecision extends Model
{
    public const STATUSES = ['attendue', 'decidee', 'ajournee', 'mise_en_oeuvre'];

    public const PRIORITIES = ['haute', 'moyenne', 'basse'];

    protected $table = 'se_decisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'decided_at' => 'datetime'];
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
