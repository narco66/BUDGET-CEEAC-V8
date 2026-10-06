<?php

namespace App\Domains\Commitments\Models;

use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'ordonnancement_id', 'montant', 'status', 'workflow_step', 'expected_actor_label',
    'mode', 'banque', 'agence', 'compte', 'titulaire', 'compte_ceeac', 'compte_modifie', 'motif_reglement',
    'reference_reglement', 'date_valeur', 'montant_paye', 'pris_en_charge_at', 'pris_en_charge_par',
    'validated_at', 'signed_at', 'signed_by', 'reconciled_at', 'reconciliation_reference', 'last_action',
    'due_on', 'return_motif', 'rejection_motif', 'bank_rejection', 'lot_id', 'tiers_bank_account_id',
    'montant_a_recouvrer',
])]
class Paiement extends Model
{
    protected $table = 'paiements';

    protected function casts(): array
    {
        return [
            'status' => PaiementStatus::class,
            'montant' => 'integer',
            'montant_paye' => 'integer',
            'montant_a_recouvrer' => 'integer',
            'compte_modifie' => 'boolean',
            'date_valeur' => 'date',
            'pris_en_charge_at' => 'datetime',
            'validated_at' => 'datetime',
            'signed_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'due_on' => 'date',
        ];
    }

    public function ordonnancement(): BelongsTo
    {
        return $this->belongsTo(Ordonnancement::class);
    }

    public function chargePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pris_en_charge_par');
    }

    public function signataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(PayLot::class, 'lot_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PayEvent::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(TiersBankAccount::class, 'tiers_bank_account_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(PaiementExecution::class)->orderBy('rang');
    }

    public function reste(): int
    {
        return max(0, (int) $this->montant - (int) $this->montant_paye);
    }
}
