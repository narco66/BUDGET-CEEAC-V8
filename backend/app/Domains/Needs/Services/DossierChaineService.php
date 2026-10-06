<?php

namespace App\Domains\Needs\Services;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use Illuminate\Support\Collection;

class DossierChaineService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function rechercher(User $user, string $terme): array
    {
        $terme = trim($terme);
        if (mb_strlen($terme) < 2) {
            return [];
        }
        $like = '%'.mb_strtolower($terme).'%';
        $ids = $this->besoins($user)
            ->where(function ($query) use ($like): void {
                $query->whereRaw('lower(reference) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(objet, \'\')) like ?', [$like]);
            })
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('id');

        $ids = $ids->merge(
            Engagement::query()->whereRaw('lower(reference) like ?', [$like])->limit(20)->pluck('expression_besoin_id'),
        );
        $ids = $ids->merge($this->besoinsDesEngagements(
            Liquidation::query()->whereRaw('lower(reference) like ?', [$like])->limit(20)->pluck('engagement_id'),
        ));
        $ids = $ids->merge($this->besoinsDesEngagements(
            Ordonnancement::query()
                ->whereRaw('lower(reference) like ?', [$like])
                ->limit(20)
                ->pluck('liquidation_id')
                ->pipe(fn (Collection $liquidationIds) => Liquidation::query()->whereIn('id', $liquidationIds)->pluck('engagement_id')),
        ));
        $ids = $ids->merge($this->besoinsDesEngagements(
            Paiement::query()
                ->whereRaw('lower(reference) like ?', [$like])
                ->limit(20)
                ->pluck('ordonnancement_id')
                ->pipe(fn (Collection $ordreIds) => Ordonnancement::query()->whereIn('id', $ordreIds)->pluck('liquidation_id'))
                ->pipe(fn (Collection $liquidationIds) => Liquidation::query()->whereIn('id', $liquidationIds)->pluck('engagement_id')),
        ));

        $retenus = $ids->filter()->unique()->take(20)->values();
        if ($retenus->isEmpty()) {
            return [];
        }

        return $this->besoins($user)
            ->with(['exercice:id,annee', 'organizationUnit:id,sigle'])
            ->withCount('engagements')
            ->whereIn('id', $retenus)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpressionBesoin $besoin): array => [
                'id' => $besoin->id,
                'reference' => $besoin->reference,
                'objet' => $besoin->objet,
                'statut' => $this->texte($besoin->status),
                'statut_libelle' => $this->libelle($besoin->status),
                'montant' => (int) $besoin->montant,
                'exercice' => $besoin->exercice?->annee,
                'unite' => $besoin->organizationUnit?->sigle,
                'engagements' => (int) $besoin->engagements_count,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function montrer(User $user, ExpressionBesoin $besoin): array
    {
        abort_unless($user->seesOrganization($besoin->organization_unit_id), 404, 'Dossier introuvable.');
        $besoin->load([
            'exercice:id,annee',
            'organizationUnit:id,sigle,name',
            'budgetLine:id,code,label',
            'engagements.liquidations.ordonnancements.paiement',
        ]);

        return [
            'id' => $besoin->id,
            'reference' => $besoin->reference,
            'objet' => $besoin->objet,
            'statut' => $this->texte($besoin->status),
            'statut_libelle' => $this->libelle($besoin->status),
            'montant' => (int) $besoin->montant,
            'exercice' => $besoin->exercice?->annee,
            'unite' => $besoin->organizationUnit?->sigle,
            'ligne' => $besoin->budgetLine?->code,
            'engagements' => $besoin->engagements
                ->sortBy('reference')
                ->values()
                ->map(fn (Engagement $engagement): array => [
                    'id' => $engagement->id,
                    'reference' => $engagement->reference,
                    'statut' => $this->texte($engagement->status),
                    'statut_libelle' => $this->libelle($engagement->status),
                    'montant' => (int) $engagement->montant,
                    'liquidations' => $engagement->liquidations
                        ->sortBy('reference')
                        ->values()
                        ->map(fn (Liquidation $liquidation): array => [
                            'id' => $liquidation->id,
                            'reference' => $liquidation->reference,
                            'statut' => $this->texte($liquidation->status),
                            'statut_libelle' => $this->libelle($liquidation->status),
                            'montant' => (int) ($liquidation->montant_net ?? $liquidation->montant),
                            'ordonnancements' => $liquidation->ordonnancements
                                ->sortBy('reference')
                                ->values()
                                ->map(fn (Ordonnancement $ordre): array => [
                                    'id' => $ordre->id,
                                    'reference' => $ordre->reference,
                                    'statut' => $this->texte($ordre->status),
                                    'statut_libelle' => $this->libelle($ordre->status),
                                    'montant' => (int) $ordre->montant,
                                    'paiement' => $ordre->paiement === null ? null : [
                                        'id' => $ordre->paiement->id,
                                        'reference' => $ordre->paiement->reference,
                                        'statut' => $this->texte($ordre->paiement->status),
                                        'statut_libelle' => $this->libelle($ordre->paiement->status),
                                        'montant' => (int) $ordre->paiement->montant,
                                        'montant_paye' => (int) $ordre->paiement->montant_paye,
                                        'titulaire' => $ordre->paiement->titulaire,
                                    ],
                                ])->all(),
                        ])->all(),
                ])->all(),
        ];
    }

    private function besoins(User $user)
    {
        $perimetre = $user->organizationScopeIds();

        return ExpressionBesoin::query()->when(
            $perimetre !== null,
            fn ($query) => $query->whereIn('organization_unit_id', $perimetre),
        );
    }

    /**
     * @param  Collection<int, mixed>  $engagementIds
     * @return Collection<int, mixed>
     */
    private function besoinsDesEngagements(Collection $engagementIds): Collection
    {
        return Engagement::query()->whereIn('id', $engagementIds->filter()->unique())->pluck('expression_besoin_id');
    }

    private function libelle(mixed $statut): string
    {
        return $statut instanceof \BackedEnum && method_exists($statut, 'label') ? (string) $statut->label() : $this->texte($statut);
    }

    private function texte(mixed $statut): string
    {
        return $statut instanceof \BackedEnum ? (string) $statut->value : (string) $statut;
    }
}
