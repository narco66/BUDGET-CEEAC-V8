<?php

namespace App\Domains\Revenues\Services;

use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenueSetting;
use App\Domains\Revenues\Notifications\RevenueAlerte;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use Illuminate\Support\Facades\Cache;

class RevenueAlertService
{
    public function run(): int
    {
        $seuil = RevenueSetting::int('approche_jours', 7);
        $orders = RevenueOrder::query()
            ->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse'])
            ->whereDate('echeance', '<=', today()->addDays($seuil))
            ->get();
        $users = app(RoleHolders::class)->query(['comptable', 'directeur_budget', 'chef_comptable'])->get();
        $sent = 0;
        foreach ($orders as $order) {
            if (! Cache::add('recette-alerte:'.$order->id.':'.today()->toDateString(), true, now()->endOfDay())) {
                continue;
            }
            $message = $order->reference.' : échéance le '.$order->echeance?->format('d/m/Y').', solde à recouvrer '.number_format($order->solde(), 0, ',', ' ').' FCFA.';
            $users->each(fn (User $user) => $user->notify(new RevenueAlerte($order, $message)));
            $sent++;
        }

        return $sent;
    }
}
