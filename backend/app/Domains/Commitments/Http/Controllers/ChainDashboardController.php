<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Services\ChainDashboardService;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ChainDashboardController extends Controller
{
    public function __invoke(ChainDashboardService $dashboard): JsonResponse
    {
        return response()->json([
            'data' => [
                'expressions' => ExpressionBesoin::query()->count(),
                'engagements' => Engagement::query()->count(),
                'liquidations' => Liquidation::query()->count(),
                'ordonnancements' => Ordonnancement::query()->count(),
                'paiements' => Paiement::query()->count(),
                'a_rapprocher' => Paiement::query()->where('status', 'a_rapprocher')->count(),
                'reste_a_payer' => app(BudgetBalanceService::class)->execution()['reste_a_payer'],
                'pilotage' => $dashboard->build(),
            ],
        ]);
    }

    public function reconciliations(): JsonResponse
    {
        $rows = Paiement::query()
            ->where('status', 'a_rapprocher')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'reference', 'montant', 'montant_paye', 'mode', 'reconciliation_reference', 'reference_reglement']);

        return response()->json(['data' => $rows]);
    }
}
