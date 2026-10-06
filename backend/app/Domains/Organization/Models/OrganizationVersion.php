<?php

namespace App\Domains\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'label', 'document_reference', 'effective_on', 'statut', 'comment', 'published_at', 'published_by'])]
class OrganizationVersion extends Model
{
    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
