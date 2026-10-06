<?php

namespace App\Domains\Revenues\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Revenues\Models\RevenueAdjustment;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenuePaymentMode;
use App\Domains\Revenues\Models\RevenueReceipt;
use App\Domains\Revenues\Notifications\RevenueAlerte;
use App\Domains\Tasks\Services\TaskProjector;
use App\Models\User;
use App\Shared\Support\NumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueCollectionService
{
    public function __construct(
        private readonly RevenueAccess $access,
        private readonly RevenueJournal $journal,
        private readonly NumberingService $numbers,
        private readonly TaskProjector $tasks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function encaisser(User $actor, array $data): RevenueReceipt
    {
        abort_unless($this->access->encaisser($actor), 403);

        return DB::transaction(function () use ($actor, $data): RevenueReceipt {
            $receipt = $this->creerRecu($actor, $data);
            $this->repartir($actor, $receipt, $data['allocations'] ?? [], $data['trop_percu'] ?? null);
            $this->tasks->sync('revenue_receipt', $receipt->id);

            return $receipt->fresh(['allocations']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function affecter(User $actor, RevenueReceipt $receipt, array $data): RevenueReceipt
    {
        abort_unless($this->access->encaisser($actor), 403);

        return DB::transaction(function () use ($actor, $receipt, $data): RevenueReceipt {
            $locked = RevenueReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->statut, ['non_identifie', 'non_rapproche'], true)) {
                throw ValidationException::withMessages(['statut' => 'Cet encaissement n’est plus affectable.']);
            }
            $this->repartir($actor, $locked, $data['allocations'] ?? [], $data['trop_percu'] ?? null, true);

            return $locked->fresh(['allocations']);
        });
    }

    public function rapprocher(User $actor, RevenueReceipt $receipt, string $statut, ?string $motif): RevenueReceipt
    {
        abort_unless($this->access->rapprocher($actor), 403);
        if (! in_array($statut, ['rapproche', 'anomalie'], true)) {
            throw ValidationException::withMessages(['statut' => 'Statut de rapprochement inconnu.']);
        }
        if ($statut === 'anomalie' && ($motif === null || trim($motif) === '')) {
            throw ValidationException::withMessages(['motif' => 'Une anomalie exige un motif.']);
        }
        if ((int) $receipt->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => 'L’agent qui a enregistré l’encaissement ne le rapproche pas.']);
        }

        return DB::transaction(function () use ($actor, $receipt, $statut, $motif): RevenueReceipt {
            $locked = RevenueReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->statut, ['non_rapproche', 'non_identifie'], true)) {
                throw ValidationException::withMessages(['statut' => 'Encaissement déjà traité.']);
            }
            $before = $locked->statut;
            $locked->forceFill([
                'statut' => $statut,
                'rapproche_par' => $actor->id,
                'rapproche_le' => now(),
            ])->save();
            $orderId = $locked->allocations()->value('order_id');
            $this->journal->write($actor, 'encaissement_rapproche', $orderId !== null ? (int) $orderId : null, $locked->id, ['statut' => $before], ['statut' => $statut], $motif);
            $this->tasks->sync('revenue_receipt', $locked->id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function creerRecu(User $actor, array $data): RevenueReceipt
    {
        $mode = RevenuePaymentMode::query()->where('code', $data['mode'] ?? '')->where('active', true)->first();
        if ($mode === null) {
            throw ValidationException::withMessages(['mode' => 'Mode d’encaissement inconnu.']);
        }
        $devise = strtoupper((string) ($data['devise'] ?? 'XAF'));
        if ($devise !== 'XAF' && empty($data['taux'])) {
            throw ValidationException::withMessages(['taux' => 'Un taux de change est requis hors franc CFA.']);
        }
        $year = (int) substr((string) $data['recu_le'], 0, 4);
        $receipt = RevenueReceipt::query()->create([
            'reference' => $this->numbers->nextEncaissement($year > 2000 ? $year : (int) now()->year),
            'recu_le' => $data['recu_le'],
            'montant' => (int) $data['montant'],
            'devise' => $devise,
            'taux' => $devise === 'XAF' ? null : $data['taux'],
            'mode' => $mode->code,
            'reference_bancaire' => $data['reference_bancaire'] ?? null,
            'banque' => $data['banque'] ?? null,
            'compte' => $data['compte'] ?? null,
            'transaction_no' => $data['transaction_no'] ?? null,
            'commentaire' => $data['commentaire'] ?? null,
            'statut' => 'non_rapproche',
            'created_by' => $actor->id,
        ]);
        $this->journal->write($actor, 'encaissement_enregistre', null, $receipt->id, null, ['reference' => $receipt->reference, 'montant' => (int) $receipt->montant]);

        return $receipt;
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     * @param  array<string, mixed>|null  $trop
     */
    private function repartir(User $actor, RevenueReceipt $receipt, array $allocations, ?array $trop, bool $complement = false): void
    {
        if (! $complement && $allocations === [] && $trop === null) {
            $receipt->forceFill(['statut' => 'non_identifie'])->save();

            return;
        }
        $deja = $complement ? (int) $receipt->allocations()->sum('montant') + (int) $receipt->adjustments()->sum('montant') : 0;
        $somme = $deja;
        foreach ($allocations as $row) {
            $somme += (int) ($row['montant'] ?? 0);
        }
        $tropMontant = (int) ($trop['montant'] ?? 0);
        $somme += $tropMontant;
        if ($somme !== (int) $receipt->montant) {
            throw ValidationException::withMessages(['allocations' => 'La somme des affectations doit être égale au montant encaissé.']);
        }
        foreach ($allocations as $row) {
            $this->allouer($actor, $receipt, (int) $row['order_id'], (int) $row['montant']);
        }
        if ($tropMontant > 0) {
            $this->tropPercu($actor, $receipt, $trop, $tropMontant);
        }
        if ($receipt->statut === 'non_identifie' && $somme === (int) $receipt->montant) {
            $receipt->forceFill(['statut' => 'non_rapproche'])->save();
        }
    }

    private function allouer(User $actor, RevenueReceipt $receipt, int $orderId, int $montant): void
    {
        if ($montant < 1) {
            throw ValidationException::withMessages(['allocations' => 'Chaque affectation est strictement positive.']);
        }
        $order = RevenueOrder::query()->whereKey($orderId)->lockForUpdate()->first();
        if ($order === null || ! $order->recouvrable()) {
            throw ValidationException::withMessages(['allocations' => 'Le titre n’est pas recouvrable.']);
        }
        $exercice = Exercice::query()->find($order->exercice_id);
        if ($exercice === null || ! $exercice->isOpen()) {
            throw ValidationException::withMessages(['exercice_id' => 'L’exercice du titre est clos.']);
        }
        if (in_array($actor->id, [(int) $order->created_by, (int) $order->validated_by], true)) {
            throw ValidationException::withMessages(['actor' => 'L’auteur ou le validateur du titre n’enregistre pas son encaissement.']);
        }
        if ($montant > $order->solde()) {
            throw ValidationException::withMessages(['allocations' => 'L’affectation dépasse le solde de '.$order->reference.'. Traitez le trop-perçu explicitement.']);
        }
        if ($receipt->allocations()->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['allocations' => 'Ce titre est déjà affecté sur cet encaissement.']);
        }
        $receipt->allocations()->create(['order_id' => $order->id, 'montant' => $montant]);
        $order->montant_encaisse = (int) $order->montant_encaisse + $montant;
        $order->statut = (int) $order->montant_encaisse === (int) $order->montant ? 'solde' : 'partiellement_encaisse';
        $order->save();
        $this->journal->write($actor, 'encaissement_affecte', $order->id, $receipt->id, null, [
            'montant' => $montant,
            'solde' => $order->solde(),
            'statut' => $order->statut,
        ]);
        $this->tasks->sync('revenue_order', $order->id);
        $order->loadMissing('author');
        $etat = $order->statut === 'solde'
            ? 'titre soldé'
            : 'reste à recouvrer '.number_format($order->solde(), 0, ',', ' ').' FCFA';
        $order->author?->notify((new RevenueAlerte(
            $order,
            $order->reference.' : encaissement '.$receipt->reference.' de '.number_format($montant, 0, ',', ' ').' FCFA, '.$etat.'.',
        ))->afterCommit());
    }

    /**
     * @param  array<string, mixed>  $trop
     */
    private function tropPercu(User $actor, RevenueReceipt $receipt, array $trop, int $montant): void
    {
        $kind = (string) ($trop['kind'] ?? '');
        if (! in_array($kind, ['avance', 'remboursement'], true)) {
            throw ValidationException::withMessages(['trop_percu' => 'Le trop-perçu se conserve en avance ou se rembourse.']);
        }
        $orderId = (int) ($trop['order_id'] ?? 0);
        $order = RevenueOrder::query()->find($orderId);
        if ($order === null) {
            throw ValidationException::withMessages(['trop_percu' => 'Le trop-perçu se rattache à un titre.']);
        }
        RevenueAdjustment::query()->create([
            'order_id' => $order->id,
            'receipt_id' => $receipt->id,
            'kind' => $kind,
            'montant' => $montant,
            'motif' => 'Trop-perçu de '.$receipt->reference,
            'author_id' => $actor->id,
        ]);
        $this->journal->write($actor, 'trop_percu', $order->id, $receipt->id, null, ['kind' => $kind, 'montant' => $montant]);
    }
}
