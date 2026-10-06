<?php

namespace App\Domains\Commitments\Models;

use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'liquidation_id',
    'montant',
    'status',
    'workflow_step',
    'expected_actor_label',
    'ordonnateur_role',
    'ordonnateur_label',
    'fondement',
    'signature_reference',
    'empreinte',
    'signature_version',
    'signed_at',
    'signed_by',
    'transmission_attempts',
    'fail_next_transmission',
    'transmission_error',
    'transmission_journal',
    'idempotence_key',
    'paiement_reference',
    'last_action',
    'due_on',
    'return_motif',
    'rejection_motif',
    'nature',
])]
class Ordonnancement extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrdonnancementStatus::class,
            'montant' => 'integer',
            'signed_at' => 'datetime',
            'transmission_attempts' => 'integer',
            'fail_next_transmission' => 'boolean',
            'transmission_journal' => 'array',
            'due_on' => 'date',
        ];
    }

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class);
    }

    public function signataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrdEvent::class);
    }

    public function paiement(): HasOne
    {
        return $this->hasOne(Paiement::class);
    }
}
