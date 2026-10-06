<?php

namespace App\Domains\Commitments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'titulaire',
    'suppleant',
    'starts_on',
    'ends_on',
    'fondement',
    'active',
])]
class OrdSuppleance extends Model
{
    protected $table = 'ord_suppleances';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function statut(): string
    {
        if ($this->active && $this->starts_on?->lte(now()) && $this->ends_on?->gte(now())) {
            return 'En cours';
        }
        if ($this->ends_on?->isPast()) {
            return 'Terminée';
        }

        return 'À venir';
    }
}
