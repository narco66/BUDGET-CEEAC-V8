<?php

namespace App\Domains\Budget\Http\Controllers;

use App\Domains\Budget\Models\BudgetProposal;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Services\PreparationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreparationController extends Controller
{
    public function __construct(private readonly PreparationService $preparation) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json($this->preparation->portrait($request->user()));
    }

    public function open(Request $request): JsonResponse
    {
        $exercice = $this->preparation->ouvrir($request->user());

        return response()->json(['data' => ['id' => $exercice->id, 'annee' => $exercice->annee]], 201);
    }

    public function adjust(Request $request, BudgetProposal $proposal): JsonResponse
    {
        $data = $request->validate(['montant' => ['required', 'integer', 'min:0']]);
        $this->preparation->ajuster($proposal, $request->user(), (int) $data['montant']);

        return response()->json(['data' => ['id' => $proposal->id]]);
    }

    public function submit(Request $request, BudgetProposal $proposal): JsonResponse
    {
        $this->preparation->soumettre($proposal, $request->user());

        return response()->json(['data' => ['id' => $proposal->id, 'statut' => 'soumis']]);
    }

    public function retain(Request $request, BudgetProposal $proposal): JsonResponse
    {
        $data = $request->validate(['retenir' => ['required', 'boolean']]);
        $this->preparation->retenir($proposal, $request->user(), (bool) $data['retenir']);

        return response()->json(['data' => ['id' => $proposal->id]]);
    }

    public function adopt(Request $request, Exercice $exercice): JsonResponse
    {
        $this->preparation->adopter($exercice, $request->user());

        return response()->json(['data' => ['id' => $exercice->id, 'statut' => 'executoire']]);
    }
}
