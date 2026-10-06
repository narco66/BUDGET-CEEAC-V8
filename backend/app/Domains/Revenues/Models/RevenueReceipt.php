<?php

namespace App\Domains\Revenues\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'recu_le', 'montant', 'devise', 'taux', 'mode', 'reference_bancaire',
    'banque', 'compte', 'transaction_no', 'commentaire', 'statut', 'created_by',
    'rapproche_par', 'rapproche_le',
])]
class RevenueReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'recu_le' => 'date',
            'montant' => 'integer',
            'taux' => 'decimal:6',
            'rapproche_le' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class, 'receipt_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RevenueDocument::class, 'receipt_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(RevenueAdjustment::class, 'receipt_id');
    }
}
