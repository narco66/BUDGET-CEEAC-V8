<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lance une fois par jour les commandes de suivi lorsque le planificateur
 * système n’est pas démarré. L’échec du lot ne bloque pas la requête.
 */
class EnsureDailyMonitoring
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->runningUnitTests()) {
            try {
                $day = now()->toDateString();
                if (Cache::add('suivi-quotidien:'.$day, true, now()->endOfDay())) {
                    Artisan::call('suivi:alertes');
                    Artisan::call('suivi:relances');
                    Artisan::call('taches:relances');
                    Artisan::call('recettes:alertes');
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $next($request);
    }
}
