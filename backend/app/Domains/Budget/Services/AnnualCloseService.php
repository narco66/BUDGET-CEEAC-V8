<?php

namespace App\Domains\Budget\Services;

use App\Domains\Budget\Models\AnnualClose;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Validation\ValidationException;

class AnnualCloseService
{
    public function __construct(
        private readonly PeriodeBudgetaireService $periodes,
        private readonly EtatsBaseService $etats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function portrait(User $actor): array
    {
        return [
            'exercices' => Exercice::query()->orderByDesc('annee')->get()->map(function (Exercice $exercice) use ($actor): array {
                $close = AnnualClose::query()->where('exercice_id', $exercice->id)->first();
                $blocages = $this->blocages($exercice);

                return [
                    'id' => $exercice->id,
                    'annee' => $exercice->annee,
                    'statut' => $exercice->statut,
                    'cloture' => $close?->statut,
                    'archive' => $close?->archive_reference,
                    'peut_archiver' => $actor->holds('secretaire_general')
                        && $exercice->statut === 'clos'
                        && $close?->statut === 'clos'
                        && blank($close->archive_reference),
                    'blocages' => count($blocages),
                    'exemples' => array_slice($blocages, 0, 8),
                    'periodes' => $this->periodes->liste($exercice),
                    'situation' => $this->etats->portrait($exercice),
                ];
            })->all(),
        ];
    }

    public function demander(Exercice $exercice, User $actor, string $motif): AnnualClose
    {
        if (! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'La demande de clôture revient au Directeur du Budget.']);
        }
        if ($exercice->statut === 'clos' || $exercice->statut === 'preparation') {
            throw ValidationException::withMessages(['exercice' => 'Cet exercice ne peut pas être clôturé dans son état actuel.']);
        }
        $blocages = $this->blocages($exercice);
        if ($blocages !== []) {
            throw ValidationException::withMessages([
                'exercice' => count($blocages).' dossier(s) encore ouverts empêchent la clôture.',
            ]);
        }
        if (AnnualClose::query()->where('exercice_id', $exercice->id)->where('statut', 'demande')->exists()) {
            throw ValidationException::withMessages(['exercice' => 'Une demande de clôture est déjà en attente.']);
        }

        $close = AnnualClose::query()->updateOrCreate(
            ['exercice_id' => $exercice->id],
            ['statut' => 'demande', 'demandeur_id' => $actor->id, 'motif' => $motif, 'validateur_id' => null, 'closed_at' => null],
        );
        FinancialAudit::record($actor, 'exercice.cloture.demandee', 'exercice', (string) $exercice->id, null, ['annee' => $exercice->annee], $motif);

        return $close;
    }

    public function confirmer(Exercice $exercice, User $actor): Exercice
    {
        $close = AnnualClose::query()->where('exercice_id', $exercice->id)->where('statut', 'demande')->first();
        if ($close === null || ! $actor->holds('secretaire_general') || $actor->id === $close->demandeur_id) {
            throw ValidationException::withMessages(['action' => 'La clôture est confirmée par le Secrétaire général, distinct du demandeur.']);
        }
        if ($this->blocages($exercice) !== []) {
            throw ValidationException::withMessages(['exercice' => 'Des dossiers se sont ouverts depuis la demande.']);
        }
        $exercice->forceFill(['statut' => 'clos'])->save();
        $close->forceFill(['statut' => 'clos', 'validateur_id' => $actor->id, 'closed_at' => now()])->save();
        FinancialAudit::record($actor, 'exercice.cloture', 'exercice', (string) $exercice->id, ['statut' => 'executoire'], ['statut' => 'clos']);

        return $exercice->fresh();
    }

    /**
     * Référence d’archive d’une clôture déjà confirmée. Ne change pas le statut
     * de l’exercice : un exercice exécutoire n’est pas clos ici.
     */
    public function archiver(Exercice $exercice, User $actor, string $reference): AnnualClose
    {
        if (! $actor->holds('secretaire_general')) {
            throw ValidationException::withMessages(['action' => 'L’archivage de la clôture revient au Secrétaire général.']);
        }
        if ($exercice->statut !== 'clos') {
            throw ValidationException::withMessages([
                'exercice' => 'Seule une clôture déjà confirmée s’archive. Cette action ne clôt pas l’exercice.',
            ]);
        }
        $close = AnnualClose::query()->where('exercice_id', $exercice->id)->where('statut', 'clos')->first();
        if ($close === null) {
            throw ValidationException::withMessages(['exercice' => 'Aucune clôture confirmée à archiver.']);
        }
        $close->forceFill([
            'archive_reference' => $reference,
            'archived_at' => now(),
        ])->save();
        FinancialAudit::record($actor, 'exercice.cloture.archivee', 'exercice', (string) $exercice->id, null, ['archive' => $reference]);

        return $close->fresh();
    }

    /**
     * @return list<array{module: string, reference: string, statut: string}>
     */
    public function blocages(Exercice $exercice): array
    {
        $rows = [];
        ExpressionBesoin::query()
            ->where('exercice_id', $exercice->id)
            ->whereNotIn('status', ['rejetee', 'annulee', 'transformee_engagement'])
            ->orderBy('reference')
            ->get(['reference', 'status'])
            ->each(function (ExpressionBesoin $row) use (&$rows): void {
                $rows[] = ['module' => 'EB', 'reference' => $row->reference, 'statut' => $row->status->value];
            });
        Engagement::query()
            ->whereHas('expressionBesoin', fn ($query) => $query->where('exercice_id', $exercice->id))
            ->whereNotIn('status', ['rejete', 'annule', 'transforme_liquidation'])
            ->orderBy('reference')
            ->get(['reference', 'status'])
            ->each(function (Engagement $row) use (&$rows): void {
                $rows[] = ['module' => 'ENG', 'reference' => $row->reference, 'statut' => $row->status->value];
            });
        Liquidation::query()
            ->whereHas('engagement.expressionBesoin', fn ($query) => $query->where('exercice_id', $exercice->id))
            ->whereNotIn('status', ['rejetee', 'annulee', 'transformee_ordonnancement'])
            ->orderBy('reference')
            ->get(['reference', 'status'])
            ->each(function (Liquidation $row) use (&$rows): void {
                $rows[] = ['module' => 'LIQ', 'reference' => $row->reference, 'statut' => $row->status->value];
            });
        Ordonnancement::query()
            ->whereHas('liquidation.engagement.expressionBesoin', fn ($query) => $query->where('exercice_id', $exercice->id))
            ->whereNotIn('status', ['rejete', 'transforme_paiement'])
            ->orderBy('reference')
            ->get(['reference', 'status'])
            ->each(function (Ordonnancement $row) use (&$rows): void {
                $rows[] = ['module' => 'ORD', 'reference' => $row->reference, 'statut' => $row->status->value];
            });
        Paiement::query()
            ->whereHas('ordonnancement.liquidation.engagement.expressionBesoin', fn ($query) => $query->where('exercice_id', $exercice->id))
            ->whereNotIn('status', ['cloture', 'rejete', 'rejete_bancaire'])
            ->orderBy('reference')
            ->get(['reference', 'status'])
            ->each(function (Paiement $row) use (&$rows): void {
                $rows[] = ['module' => 'PAY', 'reference' => $row->reference, 'statut' => $row->status->value];
            });

        return $rows;
    }
}
