<?php

namespace App\Domains\Commitments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'libelle', 'status', 'compte_debiteur', 'montant', 'reference_reglement', 'date_valeur',
])]
class PayLot extends Model
{
    protected $table = 'pay_lots';

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'date_valeur' => 'date',
        ];
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class, 'lot_id');
    }
}
