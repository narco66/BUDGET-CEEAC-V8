<?php

namespace App\Domains\Commitments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décaissement effectif rattaché à un paiement. Le payé d’un dossier est la
 * somme des exécutions non rejetées : un rejet bancaire neutralise
 * l’exécution concernée sans l’effacer (PAI-004).
 */
#[Fillable([
    'paiement_id', 'rang', 'montant', 'reference_reglement', 'mode', 'date_valeur', 'status',
    'idempotence_key', 'actor_id', 'lot_id', 'rejection_motif', 'rejected_at', 'rejected_by',
    'preuve_nom', 'preuve_chemin', 'preuve_sha256',
])]
class PaiementExecution extends Model
{
    public const EXECUTEE = 'executee';

    public const REJETEE = 'rejetee';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'rang' => 'integer',
            'date_valeur' => 'date',
            'rejected_at' => 'datetime',
        ];
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  Builder<PaiementExecution>  $query
     * @return Builder<PaiementExecution>
     */
    public function scopeExecutees(Builder $query): Builder
    {
        return $query->where('status', self::EXECUTEE);
    }
}
