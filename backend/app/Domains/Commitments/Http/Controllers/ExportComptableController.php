<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Commitments\Services\ExportComptableService;
use App\Http\Controllers\Controller;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportComptableController extends Controller
{
    public function __construct(private readonly ExportComptableService $export) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json([
            'data' => $this->export->portrait($request->user(), $this->exerciceId($request)),
        ]);
    }

    public function exporter(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $portrait = $this->export->portrait($request->user(), $this->exerciceId($request), true);
        FinancialAudit::record($request->user(), 'comptabilite.export', 'exercice', (string) $portrait['exercice'], null, [
            'annee' => $portrait['exercice'],
            'rejets' => $portrait['nombre_rejets'],
            'ecritures' => 0,
        ]);

        return response()->streamDownload(function () use ($portrait) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['section', 'evenement', 'reference', 'montant', 'motif'], ';');
            foreach ($portrait['rejets'] as $rejet) {
                fputcsv($sortie, ['rejet', $rejet['libelle'], $rejet['reference'], $rejet['montant'], $rejet['motif']], ';');
            }
            fclose($sortie);
        }, 'rejets-comptables-'.$portrait['exercice'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exerciceId(Request $request): ?int
    {
        return $request->filled('exercice_id') ? $request->integer('exercice_id') : null;
    }
}
