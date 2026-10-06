<?php

namespace App\Shared\Support;

use App\Domains\Administration\Models\NumberSequence;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\EngagementDegagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\LiquidationRectification;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PayLot;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Procurement\Models\Marche;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenueReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Attribue les références sous verrou de la table number_sequences.
 * Le compteur est d’abord aligné sur le plus haut numéro déjà émis,
 * puis incrémenté : deux créations simultanées ne peuvent pas obtenir
 * la même référence.
 */
class NumberingService
{
    public function nextExpressionBesoin(Exercice $exercice, OrganizationUnit $unit): string
    {
        return DB::transaction(function () use ($exercice, $unit): string {
            $last = ExpressionBesoin::query()
                ->where('exercice_id', $exercice->id)
                ->where('organization_unit_id', $unit->id)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('reference');

            return sprintf('EB/%d/%s/%06d', $exercice->annee, $unit->sigle, $this->tail($last) + 1);
        });
    }

    public function nextEngagement(int $year): string
    {
        return $this->allocate('ENG', $year);
    }

    public function nextVisa(int $year): string
    {
        return $this->allocate('VISA', $year);
    }

    public function nextDegagement(int $year): string
    {
        return $this->allocate('DEG', $year);
    }

    public function nextLiquidation(int $year): string
    {
        return $this->allocate('LIQ', $year);
    }

    public function nextLiquidationVisa(int $year): string
    {
        return $this->allocate('VLQ', $year);
    }

    public function nextOrdonnancement(int $year): string
    {
        return $this->allocate('ORD', $year);
    }

    public function nextSignature(int $year): string
    {
        return $this->allocate('SIG', $year);
    }

    public function nextPaiement(int $year): string
    {
        return $this->allocate('PAY', $year);
    }

    public function nextLot(int $year): string
    {
        return $this->allocate('LOT', $year);
    }

    public function nextRectification(int $year): string
    {
        return $this->allocate('RCT', $year);
    }

    /**
     * Plus haut numéro réellement émis pour une séquence et un exercice
     * (null si le code de séquence est inconnu).
     */
    public function issued(string $code, int $year): ?int
    {
        $definition = $this->definitions($year)[$code] ?? null;

        return $definition === null ? null : $this->highest($definition['model'], $definition['column'], $definition['like']);
    }

    /**
     * Crée les séquences manquantes de l’exercice et aligne chaque compteur
     * sur le plus haut numéro déjà émis. Un compteur n’est jamais reculé.
     *
     * @return list<string> codes créés ou réalignés
     */
    public function synchronize(int $year): array
    {
        $changed = [];
        foreach (array_keys($this->definitions($year)) as $code) {
            DB::transaction(function () use ($code, $year, &$changed): void {
                $issued = (int) $this->issued($code, $year);
                $sequence = NumberSequence::query()->where('code', $code)->lockForUpdate()->first();
                if ($sequence === null) {
                    NumberSequence::query()->create($this->blank($code, $year) + ['last_value' => $issued]);
                    $changed[] = $code;

                    return;
                }
                if ((int) $sequence->exercise_year === $year && (int) $sequence->last_value < $issued) {
                    $sequence->forceFill(['last_value' => $issued])->save();
                    $changed[] = $code;
                }
            });
        }

        return $changed;
    }

    private function allocate(string $code, int $year): string
    {
        $definition = $this->definitions($year)[$code];

        return DB::transaction(function () use ($code, $year, $definition): string {
            $sequence = NumberSequence::query()->where('code', $code)->lockForUpdate()->first();

            if ($sequence === null) {
                NumberSequence::query()->create($this->blank($code, $year));
                $sequence = NumberSequence::query()->where('code', $code)->lockForUpdate()->firstOrFail();
            }

            $highest = $this->highest($definition['model'], $definition['column'], $definition['like']);
            $sameYear = (int) $sequence->exercise_year === $year;
            $next = ($sameYear ? max((int) $sequence->last_value, $highest) : $highest) + 1;
            $sequence->forceFill(['last_value' => $next, 'exercise_year' => $year])->save();

            return sprintf($definition['pattern'], $year, $next);
        });
    }

    /**
     * Définition des séquences : modèle et colonne portant la référence,
     * format d’émission et motif de recherche des numéros de l’exercice.
     *
     * @return array<string, array{model: class-string<Model>, column: string, pattern: string, like: string}>
     */
    private function definitions(int $year): array
    {
        return [
            'ENG' => ['model' => Engagement::class, 'column' => 'reference', 'pattern' => 'ENG-%d-%06d', 'like' => 'ENG-'.$year.'-%'],
            'VISA' => ['model' => Engagement::class, 'column' => 'visa_reference', 'pattern' => 'VISA-%d-%06d', 'like' => 'VISA-'.$year.'-%'],
            'DEG' => ['model' => EngagementDegagement::class, 'column' => 'reference', 'pattern' => 'DEG-%d-%06d', 'like' => 'DEG-'.$year.'-%'],
            'LIQ' => ['model' => Liquidation::class, 'column' => 'reference', 'pattern' => 'LIQ/%d/%06d', 'like' => '%'.$year.'%'],
            'VLQ' => ['model' => Liquidation::class, 'column' => 'visa_reference', 'pattern' => 'VLQ-%d-%06d', 'like' => 'VLQ-'.$year.'-%'],
            'ORD' => ['model' => Ordonnancement::class, 'column' => 'reference', 'pattern' => 'ORD-%d-%06d', 'like' => 'ORD-'.$year.'-%'],
            'SIG' => ['model' => Ordonnancement::class, 'column' => 'signature_reference', 'pattern' => 'SIG-%d-%06d', 'like' => 'SIG-'.$year.'-%'],
            'PAY' => ['model' => Paiement::class, 'column' => 'reference', 'pattern' => 'PAY-%d-%06d', 'like' => 'PAY-'.$year.'-%'],
            'LOT' => ['model' => PayLot::class, 'column' => 'reference', 'pattern' => 'LOT-PAY-%d-%06d', 'like' => 'LOT-PAY-'.$year.'-%'],
            'RCT' => ['model' => LiquidationRectification::class, 'column' => 'reference', 'pattern' => 'RCT-%d-%06d', 'like' => 'RCT-'.$year.'-%'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(string $code, int $year): array
    {
        return [
            'code' => $code,
            'prefix' => $code,
            'padding' => 6,
            'separator' => '-',
            'last_value' => 0,
            'exercise_year' => $year,
            'reset_policy' => 'annuel',
        ];
    }

    public function nextMarche(int $year): string
    {
        return $this->lockedTail(Marche::class, 'reference', 'MAR-'.$year.'-%', 'MAR-%d-%06d', $year);
    }

    public function nextPrevision(int $year): string
    {
        return $this->lockedTail(RevenueForecast::class, 'code', 'PRV-'.$year.'-%', 'PRV-%d-%06d', $year);
    }

    public function nextTitre(int $year): string
    {
        return $this->lockedTail(RevenueOrder::class, 'reference', 'REC-'.$year.'-%', 'REC-%d-%06d', $year);
    }

    public function nextEncaissement(int $year): string
    {
        return $this->lockedTail(RevenueReceipt::class, 'reference', 'ENC-'.$year.'-%', 'ENC-%d-%06d', $year);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function lockedTail(string $model, string $column, string $like, string $pattern, int $year): string
    {
        return DB::transaction(function () use ($model, $column, $like, $pattern, $year): string {
            $last = $model::query()->where($column, 'like', $like)->lockForUpdate()->orderByDesc('id')->value($column);

            return sprintf($pattern, $year, $this->tail($last) + 1);
        });
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function highest(string $model, string $column, string $like): int
    {
        $max = 0;
        foreach ($model::query()->where($column, 'like', $like)->pluck($column) as $value) {
            $max = max($max, $this->tail($value));
        }

        return $max;
    }

    private function tail(mixed $last): int
    {
        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
