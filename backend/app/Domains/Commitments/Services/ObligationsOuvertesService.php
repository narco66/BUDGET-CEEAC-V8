<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ObligationsOuvertesService
{
    /**
     * Tranches descriptives, identiques au portrait des états de base.
     * Elles ne constituent pas un seuil d’arriéré.
     *
     * @var list<array{code: string, libelle: string}>
     */
    private const TRANCHES = [
        ['code' => '0_30', 'libelle' => '0 à 30 jours'],
        ['code' => '31_60', 'libelle' => '31 à 60 jours'],
        ['code' => '61_90', 'libelle' => '61 à 90 jours'],
        ['code' => '91_180', 'libelle' => '91 à 180 jours'],
        ['code' => 'plus_180', 'libelle' => 'Plus de 180 jours'],
        ['code' => 'sans_signature', 'libelle' => 'Sans date de signature'],
    ];

    /**
     * Restes à payer des ordonnancements signés.
     * Aucune ligne n’est qualifiée d’arriéré : aucun seuil n’est enregistré.
     *
     * @return array<string, mixed>
     */
    public function portrait(User $user, ?int $exerciceId = null): array
    {
        $exercice = $this->exercice($exerciceId);
        $compteurs = [];
        foreach (self::TRANCHES as $tranche) {
            $compteurs[$tranche['code']] = ['nombre' => 0, 'reste' => 0];
        }
        $obligations = [];

        $ordres = Ordonnancement::query()
            ->whereIn('status', ['signe', 'transforme_paiement'])
            ->whereHas('liquidation.engagement.expressionBesoin', fn (Builder $query) => $this->perimetre($query, $user, $exercice))
            ->with(['paiement.executions:id,paiement_id,montant,status'])
            ->get(['id', 'reference', 'montant', 'signed_at']);

        foreach ($ordres as $ordre) {
            $decaisse = $this->decaisse($ordre);
            $reste = max(0, (int) $ordre->montant - $decaisse);
            if ($reste === 0) {
                continue;
            }
            $date = $ordre->signed_at;
            $jours = $date instanceof Carbon ? $this->jours($date) : null;
            $code = $jours === null ? 'sans_signature' : $this->tranche($jours);
            $compteurs[$code]['nombre']++;
            $compteurs[$code]['reste'] += $reste;
            $obligations[] = [
                'ordonnancement_id' => $ordre->id,
                'reference' => (string) $ordre->reference,
                'signe_le' => $date?->toDateString(),
                'jours' => $jours,
                'tranche' => $code,
                'montant' => (int) $ordre->montant,
                'decaisse' => $decaisse,
                'reste' => $reste,
                'qualifie' => false,
            ];
        }

        usort($obligations, function (array $gauche, array $droite): int {
            $joursGauche = $gauche['jours'] ?? -1;
            $joursDroite = $droite['jours'] ?? -1;

            return $joursDroite <=> $joursGauche;
        });

        return [
            'exercice' => $exercice->annee,
            'seuil_enregistre' => false,
            'qualifiees' => 0,
            'montant_qualifie' => 0,
            'nombre' => count($obligations),
            'reste' => array_sum(array_column($obligations, 'reste')),
            'tranches' => array_map(fn (array $tranche): array => [
                'code' => $tranche['code'],
                'libelle' => $tranche['libelle'],
                'nombre' => $compteurs[$tranche['code']]['nombre'],
                'reste' => $compteurs[$tranche['code']]['reste'],
            ], self::TRANCHES),
            'obligations' => array_slice($obligations, 0, 40),
            'precision' => 'Le reste à payer est le montant signé diminué des décaissements exécutés. Un rejet bancaire n’est pas un décaissement. Les tranches décrivent l’ancienneté depuis la signature. Aucune obligation n’est qualifiée d’arriéré : le seuil institutionnel n’est pas enregistré.',
        ];
    }

    private function decaisse(Ordonnancement $ordre): int
    {
        $executions = $ordre->paiement?->executions;
        if ($executions !== null && $executions->isNotEmpty()) {
            return (int) $executions
                ->where('status', PaiementExecution::EXECUTEE)
                ->sum('montant');
        }

        return (int) ($ordre->paiement?->montant_paye ?? 0);
    }

    private function jours(Carbon $signature): int
    {
        $depart = $signature->copy()->startOfDay();
        $aujourdhui = now()->copy()->startOfDay();
        if ($depart->greaterThan($aujourdhui)) {
            return 0;
        }

        return (int) $depart->diffInDays($aujourdhui);
    }

    private function tranche(int $jours): string
    {
        return match (true) {
            $jours <= 30 => '0_30',
            $jours <= 60 => '31_60',
            $jours <= 90 => '61_90',
            $jours <= 180 => '91_180',
            default => 'plus_180',
        };
    }

    private function exercice(?int $exerciceId): Exercice
    {
        $exercice = Exercice::query()
            ->when($exerciceId, fn (Builder $query) => $query->whereKey($exerciceId))
            ->when($exerciceId === null, fn (Builder $query) => $query->where('statut', 'executoire'))
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
}
