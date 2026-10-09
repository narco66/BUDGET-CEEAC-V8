<?php

namespace App\Domains\Budget\Models;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\EbImputation;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\PAP\Models\PapEnrichment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'exercice_id',
    'organization_unit_id',
    'code',
    'label',
    'nature',
    'chapitre',
    'article',
    'paragraphe',
    'nature_depense',
    'montant_vote',
    'montant_ceeac',
    'montant_ptf',
    'ajustements',
    'officiel',
])]
class BudgetLine extends Model
{
    /** @var Collection<string, int>|null */
    private ?Collection $totauxMouvementsCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => BudgetNature::class,
            'montant_vote' => 'integer',
            'montant_ceeac' => 'integer',
            'montant_ptf' => 'integer',
            'ajustements' => 'integer',
            'officiel' => 'boolean',
        ];
    }

    /**
     * @param  Builder<BudgetLine>  $query
     * @return Builder<BudgetLine>
     */
    public function scopeOfficielle(Builder $query): Builder
    {
        return $query->where('officiel', true);
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function enrichment(): HasOne
    {
        return $this->hasOne(PapEnrichment::class);
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(EbImputation::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CreditMovement::class);
    }

    public function montantActualise(): int
    {
        return (int) $this->montant_vote + (int) $this->ajustements;
    }

    public function creditAutorise(): int
    {
        $mouvements = $this->totauxMouvements();
        $entrant = $mouvements->only(['ouverture', 'report', 'transfert_entrant'])->sum();
        $sortant = $mouvements->only(['annulation', 'transfert_sortant'])->sum();

        return $this->montantActualise() + $entrant - $sortant;
    }

    public function montantGele(): int
    {
        $mouvements = $this->totauxMouvements();

        return max(0, (int) $mouvements->get('gel', 0) - (int) $mouvements->get('degel', 0));
    }

    /**
     * Totaux des mouvements de crédit par nature, lus en une requête et gardés
     * sur l’instance : une liste qui affiche plusieurs dossiers de la même ligne
     * ne relit pas les mouvements à chaque rangée. Les mouvements ne s’écrivent
     * que par CreditMovementService, qui contrôle sur une instance verrouillée
     * et relue (fresh), jamais sur une instance déjà mise en cache.
     *
     * @return Collection<string, int>
     */
    private function totauxMouvements(): Collection
    {
        return $this->totauxMouvementsCache ??= $this->movements()
            ->groupBy('kind')
            ->selectRaw('kind, SUM(amount) as total')
            ->pluck('total', 'kind')
            ->map(fn ($total): int => (int) $total);
    }

    public function refresh()
    {
        $this->totauxMouvementsCache = null;

        return parent::refresh();
    }

    public function montantEngage(): int
    {
        return (int) $this->engagements()
            ->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])
            ->sum(DB::raw('montant - montant_degage'));
    }

    public function montantReserve(?int $exceptExpressionId = null): int
    {
        return (int) $this->imputations()
            ->whereHas('expressionBesoin', function ($query) use ($exceptExpressionId) {
                $query->whereIn('status', EbStatus::reserving());
                if ($exceptExpressionId) {
                    $query->whereKeyNot($exceptExpressionId);
                }
            })
            ->sum('montant');
    }

    public function disponible(?int $exceptExpressionId = null): int
    {
        return $this->creditAutorise() - $this->montantGele() - $this->montantEngage() - $this->montantReserve($exceptExpressionId);
    }
}
