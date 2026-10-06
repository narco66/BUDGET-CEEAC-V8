<?php

namespace App\Shared\Integration;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['idempotence_key', 'event', 'aggregate_type', 'aggregate_id', 'payload', 'published_at'])]
class IntegrationMessage extends Model
{
    protected $table = 'integration_outbox';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $key, string $event, string $type, string $id, array $payload): self
    {
        return self::query()->firstOrCreate(
            ['idempotence_key' => $key],
            [
                'event' => $event,
                'aggregate_type' => $type,
                'aggregate_id' => $id,
                'payload' => $payload,
            ],
        );
    }
}
