<?php

namespace App\Domains\Ged\Http\Controllers;

use App\Domains\Ged\Models\GedDocument;
use App\Domains\Ged\Services\GedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GedController
{
    public function __construct(private GedService $ged) {}

    public function referentiel(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ged->referentiel()]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->ged->liste($request->user(), [
            'q' => trim($request->string('q')->toString()),
            'statut' => trim($request->string('statut')->toString()),
            'origine' => trim($request->string('origine')->toString()),
            'exercice' => trim($request->string('exercice')->toString()),
            'vue' => trim($request->string('vue')->toString()),
            'page' => $request->integer('page', 1),
        ]));
    }

    public function show(Request $request, GedDocument $gedDocument): JsonResponse
    {
        return response()->json(['data' => $this->ged->fiche($request->user(), $gedDocument)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fichier' => ['required', 'file', 'max:'.config('ged.taille_max_ko')],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'category_id' => ['nullable', 'integer', 'exists:ged_categories,id'],
            'confidentiality' => ['nullable', 'in:public,interne,restreint,confidentiel,tres_confidentiel'],
            'exercise_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'entity_type' => ['required', 'string', 'max:64'],
            'entity_id' => ['required', 'integer'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $document = $this->ged->deposer($request->user(), $request->file('fichier'), $data);

        return response()->json(['data' => ['id' => $document->id, 'reference' => $document->reference, 'scan' => $document->scan_status]], 201);
    }

    public function version(Request $request, GedDocument $gedDocument): JsonResponse
    {
        $data = $request->validate([
            'fichier' => ['required', 'file', 'max:'.config('ged.taille_max_ko')],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $document = $this->ged->nouvelleVersion($request->user(), $gedDocument, $request->file('fichier'), $data);

        return response()->json(['data' => ['id' => $document->id, 'reference' => $document->reference, 'scan' => $document->scan_status]]);
    }

    public function download(Request $request, GedDocument $gedDocument): StreamedResponse
    {
        return $this->ged->telecharger($request->user(), $gedDocument, $request->integer('version') ?: null);
    }

    public function decider(Request $request, GedDocument $gedDocument): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:valider,rejeter,archiver,geler,supprimer,restaurer,purger'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $document = $this->ged->decider($request->user(), $gedDocument, $data['decision'], $data['motif'] ?? null);

        return response()->json(['data' => ['id' => $document->id, 'statut' => $document->status]]);
    }

    public function etiqueter(Request $request, GedDocument $gedDocument): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:64']]);
        $this->ged->etiqueter($request->user(), $gedDocument, $data['label']);

        return response()->json(['data' => ['id' => $gedDocument->id]]);
    }

    public function exporter(Request $request): BinaryFileResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);
        $chemin = $this->ged->exporter($request->user(), $data['ids']);

        return response()->download($chemin, 'bordereau-ged.zip')->deleteFileAfterSend(true);
    }

    public function dossier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->ged->dossier($request->user(), $data['type'], (int) $data['id'])]);
    }
}
