<?php

namespace App\Domains\Revenues\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'kind', 'canal', 'destinataire', 'resultat', 'prochaine_action', 'author_id'])]
class RevenueReminder extends Model
{
    protected function casts(): array
    {
        return ['prochaine_action' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RevenueOrder::class, 'order_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
