<?php

namespace App\Domains\Needs\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'expression_besoin_id',
    'uploaded_by',
    'type',
    'original_name',
    'path',
    'mime',
    'size',
    'sha256',
    'confidentialite',
    'version',
])]
class EbDocument extends Model
{
    public function expressionBesoin(): BelongsTo
    {
        return $this->belongsTo(ExpressionBesoin::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
