<?php

namespace App\Shared\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Chaque événement d’un journal de module (EB, ENG, LIQ, ORD, PAI) est
 * recopié, dans la même transaction, au journal central d’audit : un seul
 * endroit pour retracer qui a fait quoi, quand, et avec quel effet.
 */
trait MirroredInAuditJournal
{
    public static function bootMirroredInAuditJournal(): void
    {
        static::created(function (Model $event): void {
            app(AuditService::class)->refleterEvenementChaine($event);
        });
    }
}
