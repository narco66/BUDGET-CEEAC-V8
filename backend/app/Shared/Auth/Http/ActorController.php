<?php

namespace App\Shared\Auth\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActorController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => self::payload($request->user()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $current = $request->user();
        $demo = config('gesbudep.demo_impersonation') === true;

        return response()->json([
            'courant' => self::payload($current),
            'demo' => $demo,
            'acteurs' => $demo
                ? User::query()->whereNotNull('role')->orderBy('name')->get()->map(fn (User $user) => self::payload($user))
                : [self::payload($current)],
            'notifications' => $current->notifications()->latest()->limit(8)->get()->map(fn ($notification) => [
                'id' => $notification->id,
                'message' => $notification->data['message'] ?? '',
                'reference' => $notification->data['reference'] ?? '',
                'lue' => $notification->read_at !== null,
                'date' => $notification->created_at?->format('d/m/Y H:i'),
            ]),
            'non_lues' => $current->unreadNotifications()->count(),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        abort_unless(config('gesbudep.demo_impersonation') === true, 403, 'Le changement d’acteur est désactivé.');

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return response()->json(['courant' => self::payload(User::query()->findOrFail($data['user_id']))]);
    }

    /**
     * @return array{id: int, nom: string, fonction: string|null, role: string|null, initiales: string|null, structure: string|null}
     */
    public static function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'nom' => $user->name,
            'fonction' => $user->function_title,
            'role' => $user->role,
            'initiales' => $user->initials,
            'structure' => $user->organizationUnit?->structureLabel(),
        ];
    }
}
