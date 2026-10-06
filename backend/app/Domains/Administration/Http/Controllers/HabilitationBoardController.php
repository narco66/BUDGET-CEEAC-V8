<?php

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Services\GestionHabilitations;
use App\Domains\Administration\Support\AdminGate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HabilitationBoardController
{
    public function __construct(private GestionHabilitations $gestion) {}

    public function grille(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json(['data' => $this->gestion->grille(trim($request->string('q')->toString()))]);
    }

    public function enregistrer(Request $request, string $code): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'updated_at' => ['nullable', 'string'],
            'cellules' => ['required', 'array', 'min:1'],
            'cellules.*.cle' => ['required', 'string', 'max:80'],
            'cellules.*.niveau' => ['required', 'in:global,scoped,denied'],
        ]);
        $role = $this->gestion->enregistrerMatrice($request->user(), $code, $data['cellules'], $data['updated_at'] ?? null);

        return response()->json(['data' => ['code' => $role->code, 'updated_at' => $role->updated_at?->toIso8601String()]]);
    }

    public function liste(Request $request): JsonResponse
    {
        $this->autoriserLecture($request);

        return response()->json($this->gestion->liste([
            'q' => trim($request->string('q')->toString()),
            'role' => trim($request->string('role')->toString()),
            'statut' => trim($request->string('statut')->toString()),
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 25),
        ]));
    }

    public function conflit(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', 'max:64'],
        ]);
        $cible = User::query()->findOrFail($data['user_id']);

        return response()->json(['data' => ['conflit' => $this->gestion->conflit($cible, $data['role'])]]);
    }

    public function soumettre(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', 'max:64'],
            'scope_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'plafond_fcfa' => ['nullable', 'integer', 'min:0'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'origine' => ['required', 'in:nomination,delegation,interim,decision,note,autre'],
            'derogation' => ['sometimes', 'boolean'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $resultat = $this->gestion->soumettre($request->user(), $data);

        return response()->json(['data' => $resultat], 201);
    }

    public function decider(Request $request, int $habilitation): JsonResponse
    {
        $this->autoriserDecision($request);
        $data = $request->validate([
            'decision' => ['required', 'in:approuver,rejeter,suspendre,revoquer'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $this->gestion->decider($request->user(), $habilitation, $data['decision'], $data['motif'] ?? null);

        return response()->json(['data' => ['id' => $habilitation, 'decision' => $data['decision']]]);
    }

    public function droits(Request $request, User $user): JsonResponse
    {
        $this->autoriserLecture($request);

        return response()->json(['data' => $this->gestion->droits($user)]);
    }

    private function autoriserLecture(Request $request): void
    {
        $user = $request->user();
        if (AdminGate::allows($user, 'consulter') || $user?->holds('ordonnateur')) {
            return;
        }
        abort(403, 'Cet écran est réservé aux administrateurs, à l’auditeur ou à l’ordonnateur.');
    }

    private function autoriserDecision(Request $request): void
    {
        $user = $request->user();
        if ($user?->role === 'administrateur_habilitations' || $user?->holds('ordonnateur')) {
            return;
        }
        abort(403, 'La décision est réservée à l’administrateur des habilitations ou à l’ordonnateur.');
    }
}
