<?php

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Exports\AuditJournalExport;
use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Administration\Models\AuditHold;
use App\Domains\Administration\Support\AdminGate;
use App\Shared\Audit\AuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class AuditJournalController
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json($this->audit->liste($request->user(), $this->filtres($request)));
    }

    public function show(Request $request, AuditEvent $auditEvent): JsonResponse
    {
        AdminGate::authorize($request, 'consulter');

        return response()->json(['data' => $this->audit->fiche($request->user(), $auditEvent)]);
    }

    public function chronologie(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->audit->chronologie($request->user(), $data['type'], (int) $data['id'])]);
    }

    public function rectifier(Request $request, AuditEvent $auditEvent): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $event = $this->audit->rectifier($request->user(), $auditEvent, $data['motif']);

        return response()->json(['data' => ['id' => $event->id]], 201);
    }

    public function geler(Request $request): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:64'],
            'object_type' => ['nullable', 'string', 'max:64'],
            'object_id' => ['nullable', 'string', 'max:64'],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $hold = $this->audit->geler($request->user(), $data['scope'], $data['object_type'] ?? null, $data['object_id'] ?? null, $data['motif']);

        return response()->json(['data' => ['id' => $hold->id]], 201);
    }

    public function lever(Request $request, AuditHold $auditHold): JsonResponse
    {
        AdminGate::authorize($request, 'habilitations');
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->audit->leverGel($request->user(), $auditHold, $data['motif']);

        return response()->json(['data' => ['id' => $auditHold->id, 'leve' => true]]);
    }

    public function exporter(Request $request): Response
    {
        if (! in_array($request->user()?->role, ['auditeur', 'administrateur_habilitations'], true)) {
            abort(403, 'L’export du journal est réservé à l’auditeur et à l’administrateur des habilitations.');
        }
        $format = $request->string('format')->toString() ?: 'csv';
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            abort(422, 'Format d’export inconnu.');
        }
        $filtres = $this->filtres($request);
        $filtres['page'] = 1;
        $filtres['per_page'] = 500;
        $liste = $this->audit->liste($request->user(), $filtres);
        $this->audit->enregistrer($request->user(), 'audit.export', 'audit_event', null, null, [
            'filtres' => $request->only(['q', 'module', 'resultat', 'du', 'au', 'unite', 'format']),
            'fuseau' => config('audit.fuseau'),
            'format' => $format,
            'lignes' => count($liste['data']),
        ], null, 'succes', null, null, null, null, null, 'sensible');
        $fuseau = (string) config('audit.fuseau');
        if ($format === 'xlsx') {
            return Excel::download(new AuditJournalExport($liste['data'], $fuseau), 'journal-audit.xlsx');
        }
        if ($format === 'pdf') {
            return Pdf::loadHTML($this->htmlJournal($liste['data'], $fuseau))->setPaper('a4', 'landscape')->download('journal-audit.pdf');
        }

        return response()->streamDownload(function () use ($liste, $fuseau) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['quand', 'fuseau', 'acteur', 'role', 'module', 'action', 'objet', 'objet_id', 'reference', 'resultat', 'motif'], ';');
            foreach ($liste['data'] as $ligne) {
                fputcsv($sortie, [
                    $ligne['quand'], $fuseau, $ligne['acteur'], $ligne['role'], $ligne['module'],
                    $ligne['action'], $ligne['objet'], $ligne['objet_id'], $ligne['reference'], $ligne['resultat'], $ligne['motif'],
                ], ';');
            }
            fclose($sortie);
        }, 'journal-audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtres(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:64'],
            'resultat' => ['nullable', 'string', 'max:32'],
            'action' => ['nullable', 'string', 'max:80'],
            'correlation' => ['nullable', 'string', 'max:64'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'unite' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);
        $fuseau = (string) config('audit.fuseau');

        return [
            'q' => trim((string) ($data['q'] ?? '')),
            'module' => trim((string) ($data['module'] ?? '')),
            'resultat' => trim((string) ($data['resultat'] ?? '')),
            'action' => trim((string) ($data['action'] ?? '')),
            'correlation' => trim((string) ($data['correlation'] ?? '')),
            'du' => isset($data['du']) ? Carbon::parse($data['du'], $fuseau)->startOfDay()->utc()->toDateTimeString() : '',
            'au' => isset($data['au']) ? Carbon::parse($data['au'], $fuseau)->endOfDay()->utc()->toDateTimeString() : '',
            'unite' => isset($data['unite']) ? (string) $data['unite'] : '',
            'page' => (int) ($data['page'] ?? $request->integer('page', 1)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function htmlJournal(array $lignes, string $fuseau): string
    {
        $corps = '';
        foreach ($lignes as $ligne) {
            $corps .= '<tr><td>'.e($ligne['quand'] ?? '').'</td><td>'.e($ligne['acteur'] ?? '').'</td><td>'.e($ligne['role'] ?? '').'</td><td>'.e($ligne['action'] ?? '').'</td><td>'.e($ligne['reference'] ?? '').'</td><td>'.e($ligne['resultat'] ?? '').'</td></tr>';
        }

        return '<h1>Journal d’audit</h1><p>Fuseau '.e($fuseau).'. Horloge serveur, non certifiée par un tiers. '.count($lignes).' ligne(s), plafond 500.</p><table border="1" cellpadding="4"><thead><tr><th>Quand</th><th>Acteur</th><th>Rôle</th><th>Action</th><th>Référence</th><th>Résultat</th></tr></thead><tbody>'.$corps.'</tbody></table>';
    }
}
