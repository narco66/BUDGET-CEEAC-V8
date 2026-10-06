<?php

namespace App\Domains\Budget\Http\Controllers;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Services\EtatsBaseService;
use App\Domains\Tasks\Services\DelaiSuiviService;
use App\Http\Controllers\Controller;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EtatsBaseController extends Controller
{
    public function __construct(
        private readonly EtatsBaseService $etats,
        private readonly DelaiSuiviService $delais,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $exercice = $this->exercice($request);
        $portrait = $this->etats->portrait($exercice, true);
        $portrait['delais'] = $this->delais->portrait();

        return response()->json(['data' => $portrait]);
    }

    public function exporter(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $exercice = $this->exercice($request);
        $portrait = $this->etats->portrait($exercice, true);
        FinancialAudit::record($request->user(), 'etats.export', 'exercice', (string) $exercice->id, null, [
            'annee' => $exercice->annee,
            'fuseau' => config('audit.fuseau'),
        ]);

        return response()->streamDownload(function () use ($portrait) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['section', 'reference', 'statut', 'montant', 'fuseau'], ';');
            foreach (['liquidations', 'ordonnancements'] as $section) {
                foreach ($portrait['listes'][$section] as $ligne) {
                    fputcsv($sortie, [$section, $ligne['reference'], $ligne['statut'], $ligne['montant'], config('audit.fuseau')], ';');
                }
            }
            fclose($sortie);
        }, 'etats-base-'.$exercice->annee.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exercice(Request $request): Exercice
    {
        $exercice = Exercice::query()
            ->when($request->filled('exercice_id'), fn ($query) => $query->whereKey($request->integer('exercice_id')))
            ->when(! $request->filled('exercice_id'), fn ($query) => $query->where('statut', 'executoire'))
            ->orderByDesc('annee')
            ->first();
        if ($exercice === null && ! $request->filled('exercice_id')) {
            $exercice = Exercice::query()->orderByDesc('annee')->first();
        }
        abort_if($exercice === null, 404, 'Aucun exercice.');

        return $exercice;
    }
}
