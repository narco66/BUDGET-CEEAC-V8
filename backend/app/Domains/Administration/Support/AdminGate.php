<?php

namespace App\Domains\Administration\Support;

use App\Models\User;
use Illuminate\Http\Request;

class AdminGate
{
    public static function allows(?User $user, string $ability): bool
    {
        if ($user?->holds('super_admin') ?? false) {
            return true;
        }

        $role = $user?->role;

        return match ($ability) {
            'consulter' => $user?->holds('administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur') ?? false,
            'habilitations' => $role === 'administrateur_habilitations',
            'parametrage' => $role === 'administrateur_fonctionnel',
            default => false,
        };
    }

    public static function authorize(Request $request, string $ability): void
    {
        abort_unless(self::allows($request->user(), $ability), 403, self::refusal($ability));
    }

    public static function refusal(string $ability): string
    {
        return match ($ability) {
            'habilitations' => 'Cette action est réservée à l’administrateur des habilitations.',
            'parametrage' => 'Cette action est réservée à l’administrateur fonctionnel.',
            default => 'Cet écran est réservé aux administrateurs et à l’auditeur.',
        };
    }
}
