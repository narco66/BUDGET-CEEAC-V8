<?php

namespace App\Domains\Budget\Http\Controllers;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Models\PeriodeBudgetaire;
use App\Domains\Budget\Services\AnnualCloseService;
use App\Domains\Budget\Services\PeriodeBudgetaireService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClotureController extends Controller
{
    public function __construct(
        private readonly AnnualCloseService $closes,
        private readonly PeriodeBudgetaireService $periodes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json($this->closes->portrait($request->user()));
    }

    public function requestClose(Request $request, Exercice $exercice): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $close = $this->closes->demander($exercice, $request->user(), $data['motif']);

        return response()->json(['data' => ['id' => $close->id, 'statut' => $close->statut]], 201);
    }

    public function confirm(Request $request, Exercice $exercice): JsonResponse
    {
        $this->closes->confirmer($exercice, $request->user());

        return response()->json(['data' => ['id' => $exercice->id, 'statut' => 'clos']]);
    }

    public function archive(Request $request, Exercice $exercice): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $close = $this->closes->archiver($exercice, $request->user(), $data['reference']);

        return response()->json(['data' => ['archive' => $close->archive_reference, 'statut_exercice' => $exercice->fresh()->statut]]);
    }

    public function assurerPeriodes(Request $request, Exercice $exercice): JsonResponse
    {
        abort_unless($request->user()?->holds('directeur_budget', 'secretaire_general'), 403);
        $this->periodes->assurer($exercice);

        return response()->json(['data' => $this->periodes->liste($exercice)]);
    }

    public function fermerPeriode(Request $request, int $periode): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $periode = PeriodeBudgetaire::query()->findOrFail($periode);
        $fermee = $this->periodes->fermer($request->user(), $periode, $data['motif']);

        return response()->json(['data' => ['id' => $fermee->id, 'statut' => $fermee->status]]);
    }

    public function rouvrirPeriode(Request $request, int $periode): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $periode = PeriodeBudgetaire::query()->findOrFail($periode);
        $ouverte = $this->periodes->rouvrir($request->user(), $periode, $data['motif']);

        return response()->json(['data' => ['id' => $ouverte->id, 'statut' => $ouverte->status]]);
    }
}
