<?php

namespace Database\Seeders;

use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Services\OrdonnancementWorkflow;
use App\Models\User;
use Illuminate\Database\Seeder;

class CompleteDemonstrationChainSeeder extends Seeder
{
    public function run(): void
    {
        $order = Ordonnancement::query()
            ->with('paiement')
            ->where('reference', 'ORD-2026-000001')
            ->firstOrFail();

        if ($order->paiement !== null || $order->status !== OrdonnancementStatus::ASigner) {
            return;
        }

        $signatory = User::query()->where('role', $order->ordonnateur_role)->firstOrFail();

        app(OrdonnancementWorkflow::class)->sign($order, $signatory, true);
    }
}
