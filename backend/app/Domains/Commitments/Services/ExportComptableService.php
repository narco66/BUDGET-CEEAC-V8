<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Budget\Models\CreditMovement;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\LiquidationRectification;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use App\Shared\Integration\IntegrationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExportComptableService
{
    private const MOTIF = 'Aucun schéma d’écritures n’est enregistré. Aucun compte n’est affecté.';

    /**
     * @var array<string, string>
     */
    private const EVENEMENTS = [
        'engagement.vise' => 'Engagement visé',
        'engagement.degage' => 'Dégagement',
        'engagement.annule' => 'Engagement annulé',
        'liquidation.visee' => 'Liquidation visée',
        'liquidation.rectifiee' => 'Rectification de liquidation',
        'paiement.execute' => 'Décaissement exécuté',
        'credit.mouvement' => 'Mouvement de crédit',
    ];

    /**
     * Messages d’interface de l’exercice, refusés faute de schéma comptable.
     * Aucune écriture débit/crédit n’est produite.
     *
     * @return array<string, mixed>
     */
    public function portrait(User $user, ?int $exerciceId = null, bool $complet = false): array
    {
        $exercice = $this->exercice($exerciceId);
        $rejets = $this->rejets($user, $exercice);
        $compteurs = [];
        foreach ($rejets as $rejet) {
            $code = $rejet['evenement'];
            $compteurs[$code] ??= ['nombre' => 0, 'montant' => 0];
            $compteurs[$code]['nombre']++;
            $compteurs[$code]['montant'] += $rejet['montant'];
        }
        $parEvenement = [];
        foreach (self::EVENEMENTS as $code => $libelle) {
            if (! isset($compteurs[$code])) {
                continue;
            }
            $parEvenement[] = [
                'evenement' => $code,
                'libelle' => $libelle,
                'nombre' => $compteurs[$code]['nombre'],
                'montant' => $compteurs[$code]['montant'],
            ];
        }

        return [
            'exercice' => $exercice->annee,
            'schema_enregistre' => false,
            'ecritures' => [],
            'debit' => 0,
            'credit' => 0,
            'equilibre' => true,
            'nombre_rejets' => count($rejets),
            'par_evenement' => $parEvenement,
            'rejets' => $complet ? $rejets : array_slice($rejets, 0, 40),
            'precision' => 'Les messages d’interface restent en attente. Ils ne deviennent pas des écritures : aucun schéma comptable n’est enregistré, et aucun compte n’est proposé. Le débit et le crédit exportés sont à zéro. La liste est une file de rejets, pas un journal.',
        ];
    }

    /**
     * @return list<array{evenement: string, libelle: string, reference: string, montant: int, motif: string, date: string|null, cible: string|null, cible_id: int|null}>
     */
    private function rejets(User $user, Exercice $exercice): array
    {
        $messages = IntegrationMessage::query()
            ->whereIn('event', array_keys(self::EVENEMENTS))
            ->orderByDesc('id')
            ->get();
        $pieces = $this->pieces($messages);
        $perimetre = $user->organizationScopeIds();
        $rejets = [];
        foreach ($messages as $message) {
            $piece = $this->piece($message, $pieces);
            if ($piece === null || $piece['exercice_id'] !== $exercice->id) {
                continue;
            }
            if ($perimetre !== null && ! in_array($piece['unite_id'], $perimetre, true)) {
                continue;
            }
            $rejets[] = [
                'evenement' => $message->event,
                'libelle' => self::EVENEMENTS[$message->event],
                'reference' => $piece['reference'],
                'montant' => $piece['montant'],
                'motif' => self::MOTIF,
                'date' => $message->created_at?->toDateString(),
                'cible' => $piece['cible'],
                'cible_id' => $piece['cible_id'],
            ];
        }

        return $rejets;
    }

    /**
     * @param  Collection<int, IntegrationMessage>  $messages
     * @return array<string, mixed>
     */
    private function pieces(Collection $messages): array
    {
        $ids = function (string $type) use ($messages): array {
            return $messages->where('aggregate_type', $type)
                ->pluck('aggregate_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        };

        return [
            'engagement' => $this->charger(Engagement::class, $ids('engagement'), ['expressionBesoin']),
            'liquidation' => $this->charger(Liquidation::class, $ids('liquidation'), ['engagement.expressionBesoin']),
            'liquidation_rectification' => $this->charger(LiquidationRectification::class, $ids('liquidation_rectification'), ['liquidation.engagement.expressionBesoin']),
            'paiement' => $this->charger(Paiement::class, $ids('paiement'), ['ordonnancement.liquidation.engagement.expressionBesoin']),
            'credit_movement' => $this->charger(CreditMovement::class, $ids('credit_movement'), ['budgetLine']),
        ];
    }

    /**
     * @param  class-string  $modele
     * @param  list<int>  $ids
     * @param  list<string>  $relations
     * @return Collection<int, mixed>
     */
    private function charger(string $modele, array $ids, array $relations): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $modele::query()->with($relations)->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $pieces
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}|null
     */
    private function piece(IntegrationMessage $message, array $pieces): ?array
    {
        $id = (int) $message->aggregate_id;
        $payload = $message->payload ?? [];

        return match ($message->aggregate_type) {
            'engagement' => $this->depuisEngagement($pieces['engagement']->get($id), $payload),
            'liquidation' => $this->depuisLiquidation($pieces['liquidation']->get($id), $payload),
            'liquidation_rectification' => $this->depuisRectification($pieces['liquidation_rectification']->get($id), $payload),
            'paiement' => $this->depuisPaiement($pieces['paiement']->get($id), $payload),
            'credit_movement' => $this->depuisCredit($pieces['credit_movement']->get($id), $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}|null
     */
    private function depuisEngagement(?Engagement $engagement, array $payload): ?array
    {
        $besoin = $engagement?->expressionBesoin;
        if ($engagement === null || $besoin === null) {
            return null;
        }

        return $this->ancre($besoin, (string) $engagement->reference, $this->montant($payload, (int) $engagement->montant), 'engagement', $engagement->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}|null
     */
    private function depuisLiquidation(?Liquidation $liquidation, array $payload): ?array
    {
        $besoin = $liquidation?->engagement?->expressionBesoin;
        if ($liquidation === null || $besoin === null) {
            return null;
        }
        $net = (int) ($liquidation->montant_net ?? $liquidation->montant);

        return $this->ancre($besoin, (string) $liquidation->reference, $this->montant($payload, $net), 'liquidation', $liquidation->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}|null
     */
    private function depuisRectification(?LiquidationRectification $rectification, array $payload): ?array
    {
        $liquidation = $rectification?->liquidation;
        $besoin = $liquidation?->engagement?->expressionBesoin;
        if ($rectification === null || $liquidation === null || $besoin === null) {
            return null;
        }

        return $this->ancre($besoin, (string) ($rectification->reference ?: $liquidation->reference), $this->montant($payload, (int) $rectification->amount), 'liquidation', $liquidation->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}|null
     */
    private function depuisPaiement(?Paiement $paiement, array $payload): ?array
    {
        $besoin = $paiement?->ordonnancement?->liquidation?->engagement?->expressionBesoin;
        if ($paiement === null || $besoin === null) {
            return null;
        }

        return $this->ancre($besoin, (string) $paiement->reference, $this->montant($payload, (int) $paiement->montant), 'paiement', $paiement->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}|null
     */
    private function depuisCredit(?CreditMovement $mouvement, array $payload): ?array
    {
        $ligne = $mouvement?->budgetLine;
        if ($mouvement === null || $ligne === null) {
            return null;
        }

        return [
            'exercice_id' => (int) $ligne->exercice_id,
            'unite_id' => (int) $ligne->organization_unit_id,
            'reference' => (string) ($payload['ligne'] ?? $ligne->code),
            'montant' => $this->montant($payload, (int) $mouvement->amount),
            'cible' => null,
            'cible_id' => null,
        ];
    }

    /**
     * @return array{exercice_id: int, unite_id: int, reference: string, montant: int, cible: string|null, cible_id: int|null}
     */
    private function ancre(ExpressionBesoin $besoin, string $reference, int $montant, string $cible, int $cibleId): array
    {
        return [
            'exercice_id' => (int) $besoin->exercice_id,
            'unite_id' => (int) $besoin->organization_unit_id,
            'reference' => $reference,
            'montant' => $montant,
            'cible' => $cible,
            'cible_id' => $cibleId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function montant(array $payload, int $defaut): int
    {
        return array_key_exists('montant', $payload) ? (int) $payload['montant'] : $defaut;
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
}
