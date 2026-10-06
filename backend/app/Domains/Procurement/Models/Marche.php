<?php

namespace App\Domains\Procurement\Models;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'exercice_id', 'reference', 'objet', 'tiers_id', 'montant', 'procedure', 'statut',
    'notified_on', 'engagement_id', 'created_by',
])]
class Marche extends Model
{
    protected $table = 'marches';

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'notified_on' => 'date',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
