<?php

namespace App\Domains\Budget\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'dossier_id', 'line_id', 'arbitrage_id', 'version_id', 'original_name',
    'path', 'mime', 'size', 'sha256', 'version', 'remplace_id', 'uploaded_by', 'retiree_at',
])]
class BudgetPiece extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version' => 'integer',
            'retiree_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
