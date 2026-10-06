<?php

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Administration\Support\AdminGate;
use App\Domains\Organization\Models\OrganizationAssignment;
use App\Domains\Organization\Models\OrganizationPosition;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Models\OrganizationVersion;
use App\Domains\Organization\Services\OrganizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $organization) {}

    public function arbre(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->organization->arbre(),
            'version' => $this->versionActive(),
            'types' => OrganizationService::KINDS,
            'droits' => $this->droits($request),
        ]);
    }

    public function unites(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->organization->rechercher($request->query()),
            'types' => OrganizationService::KINDS,
            'droits' => $this->droits($request),
        ]);
    }

    public function show(Request $request, OrganizationUnit $unit): JsonResponse
    {
        return response()->json(['data' => $this->organization->fiche($unit), 'droits' => $this->droits($request)]);
    }

    public function enfants(OrganizationUnit $unit): JsonResponse
    {
        return response()->json(['data' => $this->organization->enfants($unit)]);
    }

    public function ancetres(OrganizationUnit $unit): JsonResponse
    {
        return response()->json(['data' => $this->organization->ancetres($unit)]);
    }

    public function responsables(OrganizationUnit $unit): JsonResponse
    {
        return response()->json(['data' => $this->organization->responsable($unit)]);
    }

    public function store(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $unit = $this->organization->creer($request->user(), $this->structureData($request, true));

        return response()->json(['data' => ['id' => $unit->id, 'code' => $unit->sigle]], 201);
    }

    public function update(Request $request, OrganizationUnit $unit): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $this->organization->modifier($request->user(), $unit, $this->structureData($request, false));

        return response()->json(['data' => ['id' => $unit->id]]);
    }

    public function activer(Request $request, OrganizationUnit $unit): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $this->organization->activer($request->user(), $unit, true);

        return response()->json(['data' => ['actif' => true]]);
    }

    public function desactiver(Request $request, OrganizationUnit $unit): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $this->organization->activer($request->user(), $unit, false);

        return response()->json(['data' => ['actif' => false]]);
    }

    public function destroy(Request $request, OrganizationUnit $unit): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $this->organization->supprimer($request->user(), $unit);

        return response()->json(['data' => ['id' => $unit->id]]);
    }

    public function fonctions(): JsonResponse
    {
        return response()->json(['data' => $this->organization->fonctions()]);
    }

    public function storeFonction(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $position = $this->organization->enregistrerFonction($request->user(), $this->fonctionData($request));

        return response()->json(['data' => ['id' => $position->id]], 201);
    }

    public function updateFonction(Request $request, OrganizationPosition $position): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $this->organization->enregistrerFonction($request->user(), $this->fonctionData($request), $position);

        return response()->json(['data' => ['id' => $position->id]]);
    }

    public function storeAffectation(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'organization_unit_id' => ['required', 'integer'],
            'position_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'motif' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);
        $assignment = $this->organization->affecter($request->user(), $data);

        return response()->json(['data' => ['id' => $assignment->id]], 201);
    }

    public function cloturerAffectation(Request $request, OrganizationAssignment $assignment): JsonResponse
    {
        AdminGate::authorize($request, 'parametrage');
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);
        $this->organization->cloturerAffectation($request->user(), $assignment, $data['motif'] ?? null);

        return response()->json(['data' => ['statut' => 'terminee']]);
    }

    public function versions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => OrganizationVersion::query()->orderByDesc('id')->get()->map(fn (OrganizationVersion $version): array => [
                'id' => $version->id,
                'code' => $version->code,
                'libelle' => $version->label,
                'document' => $version->document_reference,
                'date_effet' => $version->effective_on?->toDateString(),
                'statut' => $version->statut,
            ]),
            'droits' => $this->droits($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function structureData(Request $request, bool $creating): array
    {
        return $request->validate([
            'sigle' => [$creating ? 'required' : 'prohibited', 'string', 'max:32'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'kind' => [$creating ? 'required' : 'sometimes', 'string', 'max:32'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_technical' => ['nullable', 'boolean'],
            'effective_on' => ['nullable', 'date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fonctionData(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:999'],
            'compatible_kind' => ['nullable', 'string', 'max:32'],
            'parent_position_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function droits(Request $request): array
    {
        return [
            'voir' => $request->user() !== null,
            'gerer' => AdminGate::allows($request->user(), 'parametrage'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionActive(): ?array
    {
        $version = OrganizationVersion::query()->where('statut', 'publie')->first();
        if ($version === null) {
            return null;
        }

        return [
            'code' => $version->code,
            'libelle' => $version->label,
            'document' => $version->document_reference,
            'date_effet' => $version->effective_on?->toDateString(),
            'statut' => $version->statut,
        ];
    }
}
