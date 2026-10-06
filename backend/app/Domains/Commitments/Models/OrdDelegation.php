<?php

namespace App\Domains\Commitments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'delegant',
    'delegataire',
    'fonction',
    'seuil_max',
    'type_depense',
    'starts_on',
    'ends_on',
    'document',
    'active',
])]
class OrdDelegation extends Model
{
    protected function casts(): array
    {
        return [
            'seuil_max' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<OrdDelegation>  $query
     * @return Builder<OrdDelegation>
     */
    public function scopeCourante(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString());
    }
}
