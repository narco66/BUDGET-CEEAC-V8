<?php

namespace App\Console\Commands;

use App\Domains\Revenues\Services\RevenueAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recettes:alertes')]
#[Description('Notifie les échéances de recettes proches ou dépassées')]
class RecettesAlertes extends Command
{
    public function handle(RevenueAlertService $alerts): int
    {
        $sent = $alerts->run();
        $this->info($sent.' alerte(s) de recette.');

        return self::SUCCESS;
    }
}
