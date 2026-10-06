<?php

namespace App\Domains\Suppliers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compte de paiement d’un tiers. Un compte n’est utilisable qu’une fois
 * validé par un second acteur (CDC §39) ; il n’est jamais modifié : on le
 * désactive et on en crée un nouveau.
 */
#[Fillable([
    'tiers_id', 'banque', 'agence', 'numero', 'titulaire', 'devise', 'justificatif', 'status',
    'created_by', 'validated_by', 'validated_at', 'rejection_motif', 'deactivated_at',
])]
class TiersBankAccount extends Model
{
    public const EN_ATTENTE = 'en_attente';

    public const VALIDE = 'valide';

    public const REJETE = 'rejete';

    public const DESACTIVE = 'desactive';

    public const VIGILANCE_DAYS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isUsable(): bool
    {
        return $this->status === self::VALIDE && $this->tiers?->isActive() === true;
    }

    /**
     * Période de vigilance après validation d’un compte (CDC §39).
     */
    public function isUnderVigilance(): bool
    {
        return $this->validated_at !== null && $this->validated_at->gt(now()->subDays(self::VIGILANCE_DAYS));
    }

    public function maskedNumber(): string
    {
        $numero = (string) $this->numero;

        return strlen($numero) <= 4 ? $numero : str_repeat('•', max(0, strlen($numero) - 4)).substr($numero, -4);
    }
}
