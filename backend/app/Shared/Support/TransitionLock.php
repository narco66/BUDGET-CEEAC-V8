<?php

namespace App\Shared\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Exécute une transition métier dans une transaction, sur une copie du dossier
 * relue sous verrou exclusif (SELECT … FOR UPDATE). Deux validations
 * concurrentes, un double clic ou un rejeu réseau sont ainsi sérialisés : la
 * seconde voit l’état produit par la première et ses contrôles la refusent.
 */
final class TransitionLock
{
    /**
     * @template TModel of Model
     * @template TResult
     *
     * @param  TModel  $model
     * @param  Closure(TModel): TResult  $callback
     * @return TResult
     */
    public static function run(Model $model, Closure $callback): mixed
    {
        return DB::transaction(function () use ($model, $callback) {
            /** @var TModel $locked */
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });
    }

    /**
     * Verrouille des lignes par identifiant, dans un ordre stable pour éviter
     * les interblocages entre transactions concurrentes.
     *
     * @param  class-string<Model>  $modelClass
     * @param  iterable<int>  $ids
     */
    public static function rows(string $modelClass, iterable $ids): void
    {
        $ids = collect($ids)->filter()->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $modelClass::query()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get();
    }
}
