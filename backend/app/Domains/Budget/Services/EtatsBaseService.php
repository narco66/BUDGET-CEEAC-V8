<?php

namespace App\Domains\Budget\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use Illuminate\Database\Eloquent\Builder;

class EtatsBaseService
{
    public function __construct(private readonly BudgetBalanceService $balances) {}

    /**
     * Situations exigées pour la clôture et le pilotage, lues sur la chaîne réelle.
     * L’ancienneté décrit des dossiers ouverts. Elle ne qualifie pas un arriéré :
     * aucun seuil institutionnel n’est fixé.
     *
     * @return array<string, mixed>
     */
    public function portrait(Exercice $exercice, bool $detail = false): array
    {
        $engagements = Engagement::query()
            ->where('status', 'vise')
            ->whereHas('expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exercice->id));
        $liquidations = Liquidation::query()
            ->whereNotIn('status', ['rejetee', 'annulee', 'transformee_ordonnancement'])
            ->whereHas('engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exercice->id));
        $ordres = Ordonnancement::query()
            ->whereNotIn('status', ['rejete', 'transforme_paiement'])
            ->whereHas('liquidation.engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exercice->id));
        $aRapprocher = Paiement::query()
            ->where('status', 'a_rapprocher')
            ->whereHas('ordonnancement.liquidation.engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exercice->id));

        $portrait = [
            'exercice' => $exercice->annee,
            'engagements_vises_non_transformes' => (int) (clone $engagements)->count(),
            'montant_engagements_vises' => (int) (clone $engagements)->sum('montant'),
            'liquidations_ouvertes' => (int) (clone $liquidations)->count(),
            'ordonnancements_non_payes' => (int) (clone $ordres)->count(),
            'montant_ordonnancements_non_payes' => (int) (clone $ordres)->sum('montant'),
            'paiements_a_rapprocher' => (int) (clone $aRapprocher)->count(),
            'rejets' => $this->rejets($exercice),
            'anciennete_ordonnancements_ouverts' => $this->anciennete($ordres),
            'precision' => 'L’ancienneté classe les ordonnancements encore ouverts. Elle ne qualifie pas un arriéré : le seuil institutionnel n’est pas fixé. L’échéancier de trésorerie prévisionnel n’est pas un plan de décaissement.',
        ];
        if (! $detail) {
            return $portrait;
        }
        $portrait['credits'] = $this->credits($exercice);
        $portrait['listes'] = [
            'liquidations' => $this->lignes($liquidations, 'montant_net'),
            'ordonnancements' => $this->lignes($ordres, 'montant'),
        ];
        $portrait['fournisseurs'] = $this->fournisseurs($exercice);

        return $portrait;
    }

    private function rejets(Exercice $exercice): int
    {
        $exerciceId = $exercice->id;

        return Engagement::query()->where('status', 'rejete')->whereHas('expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exerciceId))->count()
            + Liquidation::query()->where('status', 'rejetee')->whereHas('engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exerciceId))->count()
            + Ordonnancement::query()->where('status', 'rejete')->whereHas('liquidation.engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exerciceId))->count()
            + Paiement::query()->whereIn('status', ['rejete', 'rejete_bancaire'])->whereHas('ordonnancement.liquidation.engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exerciceId))->count();
    }

    /**
     * @param  Builder<Ordonnancement>  $ordres
     * @return array<string, int>
     */
    private function anciennete(Builder $ordres): array
    {
        $bornes = [
            '0_30' => [0, 30],
            '31_60' => [31, 60],
            '61_90' => [61, 90],
            '91_180' => [91, 180],
            'plus_180' => [181, null],
        ];
        $compte = [];
        foreach ($bornes as $code => [$min, $max]) {
            $requete = (clone $ordres)->where('created_at', '<=', now()->subDays($min)->endOfDay());
            if ($max !== null) {
                $requete->where('created_at', '>=', now()->subDays($max)->startOfDay());
            }
            $compte[$code] = (int) $requete->count();
        }

        return $compte;
    }

    /**
     * @return array<string, mixed>
     */
    private function credits(Exercice $exercice): array
    {
        $lignes = BudgetLine::query()->where('exercice_id', $exercice->id)->where('officiel', true)->get(['id', 'nature']);
        $soldes = $this->balances->forLines($lignes->pluck('id'));
        $total = ['revise' => 0, 'engage' => 0, 'paye' => 0, 'disponible' => 0];
        $natures = [];
        foreach ($lignes as $ligne) {
            $solde = $soldes[$ligne->id] ?? null;
            if ($solde === null) {
                continue;
            }
            $nature = $ligne->nature instanceof \BackedEnum ? $ligne->nature->value : (string) $ligne->nature;
            $natures[$nature] ??= ['revise' => 0, 'engage' => 0, 'paye' => 0, 'disponible' => 0];
            foreach (array_keys($total) as $cle) {
                $total[$cle] += $solde[$cle];
                $natures[$nature][$cle] += $solde[$cle];
            }
        }

        return [
            'perimetre' => 'Lignes officielles de l’exercice.',
            'total' => $total,
            'natures' => $natures,
        ];
    }

    /**
     * @param  Builder<Liquidation>|Builder<Ordonnancement>  $requete
     * @return list<array{reference: string, statut: string, montant: int}>
     */
    private function lignes(Builder $requete, string $montant): array
    {
        return $requete->orderBy('reference')->limit(40)->get(['reference', 'status', $montant])
            ->map(fn ($ligne): array => [
                'reference' => (string) $ligne->reference,
                'statut' => $ligne->status instanceof \BackedEnum ? $ligne->status->value : (string) $ligne->status,
                'montant' => (int) $ligne->{$montant},
            ])->all();
    }

    /**
     * @return list<array{titulaire: string, nombre: int, montant: int}>
     */
    private function fournisseurs(Exercice $exercice): array
    {
        return Paiement::query()
            ->whereIn('status', ['paye_partiel', 'a_rapprocher', 'cloture'])
            ->whereNotNull('titulaire')
            ->whereHas('ordonnancement.liquidation.engagement.expressionBesoin', fn (Builder $query) => $query->where('exercice_id', $exercice->id))
            ->selectRaw('titulaire, COUNT(*) as nombre, SUM(montant_paye) as montant')
            ->groupBy('titulaire')
            ->orderByDesc('montant')
            ->limit(30)
            ->get()
            ->map(fn ($ligne): array => [
                'titulaire' => (string) $ligne->titulaire,
                'nombre' => (int) $ligne->nombre,
                'montant' => (int) $ligne->montant,
            ])->all();
    }
}
