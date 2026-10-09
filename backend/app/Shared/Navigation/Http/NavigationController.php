<?php

namespace App\Shared\Navigation\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Navigation\NavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    public function __invoke(Request $request, NavigationService $navigation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json($navigation->forUser($user));
    }
}
