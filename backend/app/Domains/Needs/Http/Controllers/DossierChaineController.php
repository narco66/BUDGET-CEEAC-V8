<?php

namespace App\Domains\Needs\Http\Controllers;

use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\DossierChaineService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DossierChaineController extends Controller
{
    public function __construct(private readonly DossierChaineService $dossiers) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json([
            'data' => $this->dossiers->rechercher($request->user(), (string) ($data['q'] ?? '')),
        ]);
    }

    public function show(Request $request, ExpressionBesoin $expressionBesoin): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json([
            'data' => $this->dossiers->montrer($request->user(), $expressionBesoin),
        ]);
    }
}
