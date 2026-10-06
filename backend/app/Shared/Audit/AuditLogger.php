<?php

namespace App\Shared\Audit;

use App\Domains\Needs\Models\EbEvent;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;

class AuditLogger
{
    /**
     * @param  list<string>|null  $fields
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        ExpressionBesoin $eb,
        ?User $actor,
        string $action,
        ?string $from,
        ?string $to,
        ?string $motif,
        ?string $observations,
        ?array $fields,
        ?array $payload = null,
    ): EbEvent {
        return $eb->events()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'motif' => $motif,
            'observations' => $observations,
            'fields' => $fields,
            'payload' => $payload,
        ]);
    }
}
