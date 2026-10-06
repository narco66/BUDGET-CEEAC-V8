<?php

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Services\ContinuityRegisterService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistreController extends Controller
{
    public function __construct(private readonly ContinuityRegisterService $registres) {}

    public function executionSnapshots(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json(['data' => $this->registres->instantanesExecution()]);
    }

    public function findings(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json(['data' => $this->registres->relever($request->user())]);
    }

    public function openFinding(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'gravite' => ['required', 'in:majeur,mineur'],
            'constat' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->registres->ouvrir($request->user(), $data['reference'], $data['gravite'], $data['constat']),
        ], 201);
    }

    public function closeFinding(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'motif' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->registres->cloturer($request->user(), $data['code'], $data['motif']),
        ]);
    }

    public function stageImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fichier' => ['required', 'string', 'max:120'],
            'contenu' => ['required', 'string', 'max:200000'],
        ]);

        return response()->json(['data' => $this->registres->preparerImport($request->user(), $data['fichier'], $data['contenu'])], 201);
    }

    public function importBatches(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registres->lotsImport($request->user())]);
    }

    public function review(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registres->revueOuverte($request->user())]);
    }

    public function openReview(Request $request): JsonResponse
    {
        return response()->json(['data' => ['reference' => $this->registres->ouvrirRevue($request->user())]], 201);
    }

    public function confirmReview(Request $request, string $reference): JsonResponse
    {
        $this->registres->confirmerAcces($request->user(), $reference);

        return response()->json(['data' => ['reference' => $reference, 'decision' => 'confirme']]);
    }
}
