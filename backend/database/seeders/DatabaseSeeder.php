<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdministrationSeeder::class);

        if (app()->environment('production')) {
            return;
        }

        $this->call(ExpressionBesoinSeeder::class);
        $this->call(EngagementSeeder::class);
        $this->call(LiquidationSeeder::class);
        $this->call(OrdonnancementSeeder::class);
        $this->call(CompleteDemonstrationChainSeeder::class);
        $this->call(PaiementSeeder::class);
        $this->call(TiersSeeder::class);
    }
}
