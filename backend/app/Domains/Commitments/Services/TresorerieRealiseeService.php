<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TresorerieRealiseeService
{
    private const MOIS = [
        'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
        'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
    ];

    /**
     * Ordonnancements signés et décaissements exécutés de l’exercice.
     * Aucun plan prévisionnel n’est calculé.
     *
     * @return array<string, mixed>
     */
    public function portrait(User $user, ?int $exerciceId = null): array
    {
        $exercice = $this->exercice($exerciceId);
        $ordonnance = array_fill(1, 12, 0);
        $decaisse = array_fill(1, 12, 0);
        $hors = ['ordonnance' => 0, 'decaisse' => 0];
        $sansDate = 0;

        $ordres = Ordonnancement::query()
            ->whereIn('status', ['signe', 'transforme_paiement'])
            ->whereHas('liquidation.engagement.expressionBesoin', fn ($query) => $this->perimetre($query, $user, $exercice))
            ->get(['montant', 'signed_at', 'created_at']);
        foreach ($ordres as $ordre) {
            $date = $ordre->signed_at ?? $ordre->created_at;
            $mois = $this->mois($date, (int) $exercice->annee);
            if ($mois === null) {
                $hors['ordonnance'] += (int) $ordre->montant;

                continue;
            }
            $ordonnance[$mois] += (int) $ordre->montant;
        }

        $executions = PaiementExecution::query()
            ->where('status', PaiementExecution::EXECUTEE)
            ->whereHas('paiement.ordonnancement.liquidation.engagement.expressionBesoin', fn ($query) => $this->perimetre($query, $user, $exercice))
            ->with('paiement:id,reference')
            ->orderByDesc('date_valeur')
            ->get(['id', 'paiement_id', 'montant', 'date_valeur', 'reference_reglement']);
        $lignes = [];
        foreach ($executions as $execution) {
            $mois = $this->mois($execution->date_valeur, (int) $exercice->annee);
            if ($execution->date_valeur === null) {
                $sansDate += (int) $execution->montant;
            } elseif ($mois === null) {
                $hors['decaisse'] += (int) $execution->montant;
            } else {
                $decaisse[$mois] += (int) $execution->montant;
            }
            if (count($lignes) < 30) {
                $lignes[] = [
                    'paiement_id' => $execution->paiement_id,
                    'reference' => $execution->paiement?->reference,
                    'reglement' => $execution->reference_reglement,
                    'date' => $execution->date_valeur?->toDateString(),
                    'montant' => (int) $execution->montant,
                ];
            }
        }

        $rejets = (int) PaiementExecution::query()
            ->where('status', PaiementExecution::REJETEE)
            ->whereHas('paiement.ordonnancement.liquidation.engagement.expressionBesoin', fn ($query) => $this->perimetre($query, $user, $exercice))
            ->sum('montant');

        $mois = [];
        for ($numero = 1; $numero <= 12; $numero++) {
            $mois[] = [
                'mois' => $numero,
                'libelle' => self::MOIS[$numero - 1],
                'ordonnance' => $ordonnance[$numero],
                'decaisse' => $decaisse[$numero],
                'ecart' => $ordonnance[$numero] - $decaisse[$numero],
            ];
        }

        return [
            'exercice' => $exercice->annee,
            'plan_enregistre' => false,
            'total_ordonnance' => array_sum($ordonnance),
            'total_decaisse' => array_sum($decaisse),
            'ecart' => array_sum($ordonnance) - array_sum($decaisse),
            'hors_exercice' => $hors,
            'sans_date' => $sansDate,
            'rejets' => $rejets,
            'mois' => $mois,
            'decaissements' => $lignes,
            'precision' => 'Aucun plan d’engagement ni plan de trésorerie n’est enregistré. Le tableau additionne les ordonnancements signés à leur date de signature et les décaissements exécutés à leur date de valeur, dans l’année de l’exercice. Un rejet bancaire n’est pas un décaissement. Les mois à venir ne sont pas prévus.',
        ];
    }

    private function exercice(?int $exerciceId): Exercice
    {
        $exercice = Exercice::query()
            ->when($exerciceId, fn ($query) => $query->whereKey($exerciceId))
            ->when($exerciceId === null, fn ($query) => $query->where('statut', 'executoire'))
            ->orderByDesc('annee')
            ->first();
        if ($exercice === null && $exerciceId === null) {
            $exercice = Exercice::query()->orderByDesc('annee')->first();
        }
        abort_if($exercice === null, 404, 'Aucun exercice.');

        return $exercice;
    }

    private function perimetre(Builder $query, User $user, Exercice $exercice): void
    {
        $query->where('exercice_id', $exercice->id);
        $perimetre = $user->organizationScopeIds();
        if ($perimetre !== null) {
            $query->whereIn('organization_unit_id', $perimetre);
        }
    }

    private function mois(mixed $date, int $annee): ?int
    {
        if ($date === null) {
            return null;
        }
        $jour = Carbon::parse($date);

        return $jour->year === $annee ? $jour->month : null;
    }
}
