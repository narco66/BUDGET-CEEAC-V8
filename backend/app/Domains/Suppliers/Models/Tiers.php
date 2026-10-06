<?php

namespace App\Domains\Suppliers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Fiche tiers unique (CDC §15, §39) : fournisseur, consultant ou bénéficiaire.
 */
#[Fillable([
    'code', 'type', 'raison_sociale', 'nom_normalise', 'nif', 'rccm', 'pays', 'adresse', 'email', 'telephone',
    'status', 'status_motif', 'created_by', 'merged_into_id',
])]
class Tiers extends Model
{
    public const TYPES = ['fournisseur', 'consultant', 'beneficiaire', 'organisme'];

    public const STATUSES = ['actif', 'suspendu', 'bloque', 'archive'];

    protected $table = 'tiers';

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(TiersBankAccount::class)->latest('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'actif';
    }

    /**
     * Clé de rapprochement des libellés (doublons, bénéficiaires historiques) :
     * minuscules, sans accents, sans ponctuation ni forme juridique courante.
     */
    public static function normalize(string $name): string
    {
        $ascii = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->toString();
        $words = array_filter(explode(' ', $ascii), fn (string $word) => $word !== '' && ! in_array($word, ['sa', 'sarl', 'sas', 'sarlu', 'ets', 'etablissements', 'ste', 'societe', 'cabinet'], true));

        return implode(' ', $words);
    }
}
