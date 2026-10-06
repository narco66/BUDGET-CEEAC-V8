<?php

namespace App\Domains\Monitoring\Models;

use LogicException;

/**
 * Une donnée S&E validée n’est ni modifiée ni supprimée (description S&E
 * §29, §58, §98). Seuls le passage à « consolidé » et le marquage de
 * remplacement par une rectification restent possibles. PostgreSQL applique
 * la même règle par trigger.
 */
trait ProtectsValidatedValues
{
    /**
     * @var list<string>
     */
    private static array $mutableWhenValidated = ['status', 'superseded_at', 'updated_at', 'consolidated_by', 'consolidated_at'];

    public static function bootProtectsValidatedValues(): void
    {
        static::updating(function (self $model): void {
            if (! in_array($model->getOriginal('status'), ['valide', 'consolide'], true)) {
                return;
            }
            $changed = array_diff(array_keys($model->getDirty()), self::$mutableWhenValidated);
            if ($changed !== [] || ! in_array($model->status, ['valide', 'consolide'], true)) {
                throw new LogicException('Donnée S&E validée : modification interdite, utilisez une rectification.');
            }
        });

        static::deleting(function (self $model): void {
            if (in_array($model->getOriginal('status'), ['valide', 'consolide'], true)) {
                throw new LogicException('Donnée S&E validée : suppression interdite.');
            }
        });
    }
}
