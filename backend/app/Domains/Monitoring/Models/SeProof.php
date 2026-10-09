<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeProof extends Model
{
    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'proofable_type',
        'proofable_id',
        'category',
        'path',
        'sha256',
        'confidentiality',
        'version',
        'author_id',
        'organization_unit_id',
    ];
}
