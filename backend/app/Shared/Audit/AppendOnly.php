<?php

namespace App\Shared\Audit;

use LogicException;

/**
 * Journal en ajout seul (CDC §25, §54) : une trace créée ne peut être ni
 * modifiée ni supprimée par l’application. PostgreSQL applique la même
 * règle par trigger.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (): never {
            throw new LogicException('Journal en ajout seul : modification interdite.');
        });

        static::deleting(function (): never {
            throw new LogicException('Journal en ajout seul : suppression interdite.');
        });
    }
}
