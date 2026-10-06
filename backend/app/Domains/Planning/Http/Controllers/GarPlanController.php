<?php

namespace App\Domains\Planning\Http\Controllers;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Planning\Models\GarNode;
use App\Domains\Planning\Models\GarVersion;
use App\Domains\Planning\Services\GarPlanService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GarPlanController extends Controller
{
    public function __construct(private readonly GarPlanService $plans) {}

    public function index(Request $request): JsonResponse
    {
        abort_if(! $request->user()->holdsAny(), 403);

        return response()->json($this->plans->portrait($this->exercice($request), $request->user()));
    }

    public function initialiser(Request $request): JsonResponse
    {
        $exercice = $this->exercice($request);
        $version = $this->plans->initialiser($exercice, $request->user());

        return response()->json($this->plans->portrait($exercice->fresh(), $request->user()) + ['version_ouverte' => $version->id], 201);
    }

    public function storeNode(Request $request, GarVersion $version): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(GarNode::TYPES)],
            'parent_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:32'],
            'libelle' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'objectifs' => ['nullable', 'string'],
            'resultats_attendus' => ['nullable', 'string'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'periode' => ['nullable', 'string', 'max:255'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
            'indicateur' => ['nullable', 'string', 'max:255'],
            'unite_mesure' => ['nullable', 'string', 'max:64'],
            'cible' => ['nullable', 'string', 'max:255'],
            'enveloppe' => ['nullable', 'integer', 'min:0'],
            'budget_line_id' => ['nullable', 'integer'],
            'contributeurs' => ['nullable', 'array'],
            'contributeurs.*' => ['integer', 'exists:organization_units,id'],
        ]);
        $node = $this->plans->creer($version, $request->user(), $data);

        return response()->json(['data' => ['id' => $node->id, 'code' => $node->code]], 201);
    }

    public function updateNode(Request $request, GarNode $node): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'code' => ['sometimes', 'string', 'max:32'],
            'libelle' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'objectifs' => ['nullable', 'string'],
            'resultats_attendus' => ['nullable', 'string'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'periode' => ['nullable', 'string', 'max:255'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
            'indicateur' => ['nullable', 'string', 'max:255'],
            'unite_mesure' => ['nullable', 'string', 'max:64'],
            'cible' => ['nullable', 'string', 'max:255'],
            'enveloppe' => ['nullable', 'integer', 'min:0'],
            'budget_line_id' => ['nullable', 'integer'],
            'contributeurs' => ['nullable', 'array'],
            'contributeurs.*' => ['integer', 'exists:organization_units,id'],
            'justification' => ['nullable', 'string', 'max:255'],
        ]);
        $this->plans->modifier($node, $request->user(), $data);

        return response()->json(['data' => ['id' => $node->id]]);
    }

    public function archiveNode(Request $request, GarNode $node): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->plans->archiver($node, $request->user(), $data['motif']);

        return response()->json(['data' => ['id' => $node->id, 'statut' => 'archive']]);
    }

    public function soumettre(Request $request, GarVersion $version): JsonResponse
    {
        $data = $request->validate(['justification' => ['required', 'string', 'max:255']]);
        $version = $this->plans->soumettre($version, $request->user(), $data['justification']);

        return response()->json(['data' => ['id' => $version->id, 'statut' => $version->statut]]);
    }

    public function valider(Request $request, GarVersion $version): JsonResponse
    {
        $version = $this->plans->valider($version, $request->user());

        return response()->json(['data' => ['id' => $version->id, 'statut' => $version->statut]]);
    }

    public function publier(Request $request, GarVersion $version): JsonResponse
    {
        $data = $request->validate(['date_effet' => ['required', 'date']]);
        $version = $this->plans->publier($version, $request->user(), $data['date_effet']);

        return response()->json(['data' => ['id' => $version->id, 'statut' => $version->statut]]);
    }

    public function avenant(Request $request, GarVersion $version): JsonResponse
    {
        $data = $request->validate(['justification' => ['required', 'string', 'max:255']]);
        $copie = $this->plans->avenant($version, $request->user(), $data['justification']);

        return response()->json(['data' => ['id' => $copie->id, 'numero' => $copie->numero, 'statut' => $copie->statut]], 201);
    }

    private function exercice(Request $request): Exercice
    {
        $annee = (int) $request->integer('annee');
        if ($annee === 0) {
            $annee = (int) Exercice::query()->whereIn('statut', ['ouvert', 'executoire'])->orderByDesc('annee')->value('annee');
        }

        return Exercice::query()->where('annee', $annee)->firstOrFail();
    }
}
