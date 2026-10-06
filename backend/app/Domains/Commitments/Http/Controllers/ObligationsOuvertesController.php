<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Commitments\Services\ObligationsOuvertesService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObligationsOuvertesController extends Controller
{
    public function __construct(private readonly ObligationsOuvertesService $obligations) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json([
            'data' => $this->obligations->portrait($request->user(), $request->filled('exercice_id') ? $request->integer('exercice_id') : null),
        ]);
    }
}
