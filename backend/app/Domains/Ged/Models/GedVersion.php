<?php

namespace App\Domains\Ged\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GedVersion extends Model
{
    protected $fillable = [
        'ged_document_id', 'version_number', 'disk', 'path', 'original_filename', 'mime',
        'extension', 'size', 'sha256', 'uploaded_by', 'change_reason', 'is_current', 'is_signed', 'scan_status', 'extracted_text',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'is_signed' => 'boolean',
            'size' => 'integer',
            'version_number' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GedDocument::class, 'ged_document_id');
    }
}
