<?php

namespace App\Domains\Budget\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Models\PeriodeBudgetaire;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PeriodeBudgetaireService
{
    /** @var list<string> */
    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * Crée les douze mois ouverts s'ils manquent. Ne ferme aucune période.
     */
    public function assurer(Exercice $exercice): void
    {
        foreach (range(1, 12) as $mois) {
            $debut = Carbon::create((int) $exercice->annee, $mois, 1)->startOfDay();
            PeriodeBudgetaire::query()->firstOrCreate(
                ['exercice_id' => $exercice->id, 'code' => sprintf('%d-%02d', $exercice->annee, $mois)],
                [
                    'label' => self::MOIS[$mois - 1].' '.$exercice->annee,
                    'starts_on' => $debut->toDateString(),
                    'ends_on' => $debut->copy()->endOfMonth()->toDateString(),
                    'status' => 'ouvert',
                ],
            );
        }
    }

    public function assertDateOuverte(Exercice $exercice, Carbon $date): void
    {
        $this->assurer($exercice);
        $periode = PeriodeBudgetaire::query()
            ->where('exercice_id', $exercice->id)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();
        if ($periode === null) {
            return;
        }
        if ($periode->status !== 'ouvert') {
            throw ValidationException::withMessages([
                'periode' => 'La période '.$periode->label.' est fermée. Aucune opération financière n’y est possible.',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function liste(Exercice $exercice): array
    {
        $this->assurer($exercice);

        return PeriodeBudgetaire::query()
            ->where('exercice_id', $exercice->id)
            ->orderBy('starts_on')
            ->get()
            ->map(fn (PeriodeBudgetaire $periode): array => [
                'id' => $periode->id,
                'code' => $periode->code,
                'label' => $periode->label,
                'debut' => $periode->starts_on?->toDateString(),
                'fin' => $periode->ends_on?->toDateString(),
                'statut' => $periode->status,
                'motif' => $periode->motif,
                'peut_fermer' => $periode->status === 'ouvert' && $periode->ends_on !== null && $periode->ends_on->lt(now()->startOfDay()),
            ])
            ->all();
    }

    public function fermer(User $actor, PeriodeBudgetaire $periode, string $motif): PeriodeBudgetaire
    {
        if (! $actor->holds('directeur_budget', 'secretaire_general')) {
            throw ValidationException::withMessages(['action' => 'La fermeture d’une période revient au Directeur du Budget ou au Secrétaire général.']);
        }
        if ($periode->status !== 'ouvert') {
            throw ValidationException::withMessages(['periode' => 'Cette période n’est pas ouverte.']);
        }
        if ($periode->ends_on === null || $periode->ends_on->gte(now()->startOfDay())) {
            throw ValidationException::withMessages(['periode' => 'La période en cours ou une période future reste ouverte.']);
        }
        $periode->forceFill([
            'status' => 'ferme',
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'motif' => $motif,
        ])->save();
        FinancialAudit::record($actor, 'periode.fermee', 'fiscal_period', (string) $periode->id, ['statut' => 'ouvert'], ['statut' => 'ferme', 'code' => $periode->code], $motif);

        return $periode;
    }

    public function rouvrir(User $actor, PeriodeBudgetaire $periode, string $motif): PeriodeBudgetaire
    {
        if (! $actor->holds('secretaire_general')) {
            throw ValidationException::withMessages(['action' => 'La réouverture d’une période revient au Secrétaire général.']);
        }
        if ($periode->status !== 'ferme') {
            throw ValidationException::withMessages(['periode' => 'Seule une période fermée se rouvre.']);
        }
        $periode->forceFill([
            'status' => 'ouvert',
            'closed_at' => null,
            'closed_by' => null,
            'motif' => $motif,
        ])->save();
        FinancialAudit::record($actor, 'periode.rouverte', 'fiscal_period', (string) $periode->id, ['statut' => 'ferme'], ['statut' => 'ouvert', 'code' => $periode->code], $motif);

        return $periode;
    }
}
