<?php

namespace App\Domains\Commitments\Models;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libération au disponible d’une part non liquidée d’un engagement visé
 * (CDC §9.12, §9.13). L’acte d’engagement n’est jamais modifié : le dégagement
 * est une opération distincte, tracée et non modifiable.
 */
#[Fillable(['reference', 'engagement_id', 'montant', 'engage_net_avant', 'motif', 'acte', 'actor_id'])]
class EngagementDegagement extends Model
{
    use AppendOnly;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'engage_net_avant' => 'integer',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
