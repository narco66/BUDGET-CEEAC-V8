<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['exercice_id', 'statut', 'demandeur_id', 'validateur_id', 'motif', 'closed_at', 'archive_reference', 'archived_at'])]
class AnnualClose extends Model
{
    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validateur_id');
    }
}
