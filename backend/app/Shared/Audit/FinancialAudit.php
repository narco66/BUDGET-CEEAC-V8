<?php

namespace App\Shared\Audit;

use App\Models\User;

class FinancialAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(User $actor, string $action, string $type, string $id, ?array $before = null, ?array $after = null, ?string $motif = null): void
    {
        app(AuditService::class)->enregistrer($actor, $action, $type, $id, $before, $after, $motif);
    }
}
