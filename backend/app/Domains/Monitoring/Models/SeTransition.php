<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeTransition extends Model
{
    public $timestamps = false;

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'from_status',
        'action',
        'to_status',
    ];
}
