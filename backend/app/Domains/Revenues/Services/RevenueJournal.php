<?php

namespace App\Domains\Revenues\Services;

use App\Domains\Revenues\Models\RevenueEvent;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;

class RevenueJournal
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function write(User $actor, string $action, ?int $orderId, ?int $receiptId, ?array $before = null, ?array $after = null, ?string $motif = null): void
    {
        RevenueEvent::query()->create([
            'order_id' => $orderId,
            'receipt_id' => $receiptId,
            'action' => $action,
            'user_id' => $actor->id,
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
        ]);
        $forecastId = $after['forecast_id'] ?? $before['forecast_id'] ?? null;
        [$subject, $subjectId] = match (true) {
            $orderId !== null => ['revenue_order', (string) $orderId],
            $receiptId !== null => ['revenue_receipt', (string) $receiptId],
            $forecastId !== null => ['revenue_forecast', (string) $forecastId],
            default => ['revenue', '0'],
        };
        FinancialAudit::record($actor, $action, $subject, $subjectId, $before, $after, $motif);
    }
}
