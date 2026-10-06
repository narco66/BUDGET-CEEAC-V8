<?php

namespace App\Domains\Planning\Models;

use App\Domains\Budget\Models\Exercice;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'exercice_id', 'numero', 'statut', 'effective_on', 'author_id', 'validator_id',
    'justification', 'published_at', 'source_version_id',
])]
class GarVersion extends Model
{
    public const BROUILLON = 'brouillon';

    public const EN_VALIDATION = 'en_validation';

    public const VALIDE = 'valide';

    public const PUBLIE = 'publie';

    public const ARCHIVE = 'archive';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'effective_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_version_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(GarNode::class)->orderBy('position')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->statut === self::BROUILLON;
    }
}
