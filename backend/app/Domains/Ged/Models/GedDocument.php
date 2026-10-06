<?php

namespace App\Domains\Ged\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GedDocument extends Model
{
    protected $fillable = [
        'uuid', 'reference', 'title', 'description', 'document_type_id', 'category_id',
        'status', 'confidentiality', 'current_version_id', 'owner_user_id', 'organization_unit_id',
        'exercise_year', 'origin', 'generated_document_id', 'source_table', 'source_id',
        'frozen_at', 'archived_at', 'deleted_at', 'scan_status',
    ];

    protected function casts(): array
    {
        return [
            'frozen_at' => 'datetime',
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
            'exercise_year' => 'integer',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GedVersion::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(GedLink::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(GedVersion::class, 'current_version_id');
    }
}
