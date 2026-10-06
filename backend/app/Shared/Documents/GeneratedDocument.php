<?php

namespace App\Shared\Documents;

use App\Models\User;
use App\Shared\Audit\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Version archivée d’un acte officiel (CDC §8.9, §18.3, §26) : fichier
 * immuable, empreinte SHA-256, code de vérification et snapshot des données
 * au moment de la génération.
 */
#[Fillable([
    'verification_code', 'documentable_type', 'documentable_id', 'kind', 'version', 'business_reference',
    'event', 'path', 'filename', 'sha256', 'size', 'snapshot', 'generated_by', 'supersedes_id',
])]
class GeneratedDocument extends Model
{
    use AppendOnly;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->kind,
            'reference' => $this->business_reference,
            'version' => $this->version,
            'evenement' => $this->event,
            'sha256' => $this->sha256,
            'code_verification' => $this->verification_code,
            'taille' => $this->size,
            'genere_par' => $this->generatedBy?->name,
            'genere_le' => $this->created_at?->toDateTimeString(),
        ];
    }
}
