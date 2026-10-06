<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Commitments\Services\TresorerieRealiseeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TresorerieController extends Controller
{
    public function __construct(private readonly TresorerieRealiseeService $tresorerie) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json([
            'data' => $this->tresorerie->portrait($request->user(), $request->filled('exercice_id') ? $request->integer('exercice_id') : null),
        ]);
    }
}
