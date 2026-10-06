<?php

namespace App\Domains\Administration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ip', 'user_agent', 'last_seen', 'revoked_at'])]
class UserSession extends Model
{
    protected function casts(): array
    {
        return [
            'last_seen' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
