<?php

namespace App\Domains\Needs\Http\Controllers;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Exports\ExpressionsBesoinExport;
use App\Domains\Needs\Http\Requests\ReturnExpressionBesoinRequest;
use App\Domains\Needs\Http\Requests\StoreExpressionBesoinRequest;
use App\Domains\Needs\Http\Requests\UpdateExpressionBesoinRequest;
use App\Domains\Needs\Http\Resources\ExpressionBesoinResource;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinFichePresenter;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Domains\PAP\Models\PapTask;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpressionBesoinController extends Controller
{
    public function __construct(private readonly ExpressionBesoinWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExpressionBesoin::class);
        $paginator = $this->filtered($request)
            ->with([
                'organizationUnit.parent',
                'initiator',
                'budgetLine.enrichment.tasks',
                'engagement',
            ])
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        return ExpressionBesoinResource::collection($paginator)
            ->additional(['tableau_de_bord' => $this->dashboard($request->user())])
            ->response();
    }

    public function show(ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('view', $expressionBesoin);

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin));
    }

    public function store(StoreExpressionBesoinRequest $request): JsonResponse
    {
        $this->authorize('create', ExpressionBesoin::class);
        $line = BudgetLine::query()->findOrFail($request->integer('budget_line_id'));
        $eb = $this->workflow->createDraft($request->user(), $line);

        return (new ExpressionBesoinResource($this->loadDetail($eb)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateExpressionBesoinRequest $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('update', $expressionBesoin);
        $this->guardEditable($request, $expressionBesoin);
        $data = $request->validated();
        $lines = $data['lignes'] ?? null;
        $imputations = array_key_exists('imputations', $data) ? $data['imputations'] : null;
        unset($data['lignes'], $data['imputations']);

        if (is_array($lines)) {
            $this->workflow->syncDetails($expressionBesoin, $data, $lines, $imputations);
        } else {
            $expressionBesoin->update($data);
        }

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function submit(Request $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('submit', $expressionBesoin);
        $this->workflow->submit($expressionBesoin, $request->user());

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function validateStep(Request $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('validateStep', $expressionBesoin);
        $this->workflow->validateStep($expressionBesoin, $request->user());
        $this->archiveOfficial($expressionBesoin->fresh(), 'approbation', $request->user());

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function returnForCorrection(ReturnExpressionBesoinRequest $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('sendBack', $expressionBesoin);
        $this->workflow->returnForCorrection(
            $expressionBesoin,
            $request->user(),
            $request->string('motif')->toString(),
            $request->string('observations')->toString(),
            $request->input('champs', []),
        );

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function reject(ReturnExpressionBesoinRequest $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('reject', $expressionBesoin);
        $this->workflow->reject(
            $expressionBesoin,
            $request->user(),
            $request->string('motif')->toString(),
            $request->string('observations')->toString(),
        );

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function approve(Request $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('approve', $expressionBesoin);
        $this->workflow->approve($expressionBesoin, $request->user());
        $this->archiveOfficial($expressionBesoin->fresh(), 'approbation', $request->user());

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function transform(Request $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('transform', $expressionBesoin);
        $this->workflow->transform($expressionBesoin, $request->user());
        $this->archiveOfficial($expressionBesoin->fresh(), 'approbation', $request->user());

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function cancel(Request $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('cancel', $expressionBesoin);
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
        ]);

        $this->workflow->cancel($expressionBesoin, $request->user(), $data['motif']);

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function duplicate(Request $request, ExpressionBesoin $expressionBesoin): JsonResponse
    {
        $this->authorize('duplicate', $expressionBesoin);
        $copy = $this->workflow->duplicate($expressionBesoin, $request->user());

        return (new ExpressionBesoinResource($this->loadDetail($copy)))
            ->response()
            ->setStatusCode(201);
    }

    public function storeDocument(Request $request, ExpressionBesoin $expressionBesoin): ExpressionBesoinResource
    {
        $this->authorize('upload', $expressionBesoin);
        $this->guardEditable($request, $expressionBesoin);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'fichier' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('fichier');
        $path = $file->store('eb-documents/'.$expressionBesoin->id);

        $expressionBesoin->documents()->create([
            'uploaded_by' => $request->user()->id,
            'type' => $data['type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);

        $expressionBesoin->events()->create([
            'actor_id' => $request->user()->id,
            'action' => 'piece',
            'to_status' => $expressionBesoin->status->value,
            'observations' => $data['type'].' — '.$file->getClientOriginalName(),
        ]);

        return new ExpressionBesoinResource($this->loadDetail($expressionBesoin->fresh()));
    }

    public function proposeTask(Request $request, BudgetLine $budgetLine): JsonResponse
    {
        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:255'],
        ]);

        $enrichment = $budgetLine->enrichment;
        if (! $enrichment) {
            throw ValidationException::withMessages([
                'libelle' => 'Cette ligne ne dispose pas d’un référentiel PAP.',
            ]);
        }

        $task = PapTask::query()->create([
            'pap_enrichment_id' => $enrichment->id,
            'position' => $enrichment->tasks()->count() + 1,
            'label' => $data['libelle'],
            'proposed' => true,
            'validated' => false,
        ]);

        return response()->json([
            'id' => $task->id,
            'libelle' => $task->label,
            'proposee' => true,
            'validee' => false,
        ], 201);
    }

    public function pdf(Request $request, ExpressionBesoin $expressionBesoin): StreamedResponse
    {
        $this->authorize('pdf', $expressionBesoin);

        if (! in_array($expressionBesoin->status, [EbStatus::Approuvee, EbStatus::Transformee], true)) {
            throw ValidationException::withMessages([
                'pdf' => 'Le PDF officiel est généré après approbation.',
            ]);
        }

        $documents = app(OfficialDocumentService::class);
        $document = $request->filled('version')
            ? $documents->version($expressionBesoin, 'expression_besoin', $request->integer('version'))
            : ($documents->current($expressionBesoin, 'expression_besoin') ?? $this->archiveOfficial($expressionBesoin, 'premiere_consultation', $request->user(), quietly: false));
        abort_if($document === null, 404, 'Version introuvable.');

        return $documents->download($document);
    }

    /**
     * Aperçu non archivé tant que l’EB n’est pas approuvée. Après approbation,
     * la même adresse restitue la révision archivée, sans en créer une autre.
     */
    public function apercu(Request $request, ExpressionBesoin $expressionBesoin): Response|StreamedResponse
    {
        $this->authorize('pdf', $expressionBesoin);
        if (in_array($expressionBesoin->status, [EbStatus::Approuvee, EbStatus::Transformee], true)) {
            return $this->pdf($request, $expressionBesoin);
        }

        $fiche = app(ExpressionBesoinFichePresenter::class)->present($this->loadDetail($expressionBesoin));
        $bytes = Pdf::loadView('pdf.expression-besoin', ['fiche' => $fiche])->setOption('isPhpEnabled', true)->setPaper('a4', 'portrait')->output();
        $filename = str_replace(['/', '\\'], '-', $expressionBesoin->reference).'-brouillon.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * La fiche EB officielle est archivée une seule fois, à l’approbation
     * (EB-006) : les transitions suivantes ne la régénèrent pas.
     */
    private function archiveOfficial(ExpressionBesoin $eb, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        $documents = app(OfficialDocumentService::class);
        if (! in_array($eb->status, [EbStatus::Approuvee, EbStatus::Transformee], true) || $documents->current($eb, 'expression_besoin') !== null) {
            return null;
        }
        $arguments = [$eb, 'expression_besoin', $eb->reference, 'pdf.expression-besoin', ['fiche' => app(ExpressionBesoinFichePresenter::class)->present($this->loadDetail($eb))], $event, $actor];

        return $quietly ? $documents->archiveQuietly(...$arguments) : $documents->archive(...$arguments);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', ExpressionBesoin::class);
        $rows = $this->filtered($request)->with([
            'organizationUnit.parent',
            'budgetLine',
            'engagement',
        ])->get();

        return Excel::download(new ExpressionsBesoinExport($rows), 'expressions-de-besoin.xlsx');
    }

    private function filtered(Request $request)
    {
        $query = ExpressionBesoin::query();

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($inner) use ($search) {
                $inner->where('reference', 'like', "%{$search}%")
                    ->orWhere('objet', 'like', "%{$search}%")
                    ->orWhereHas('budgetLine', function ($line) use ($search) {
                        $line->where('code', 'like', "%{$search}%")
                            ->orWhere('label', 'like', "%{$search}%");
                    });
            });
        }

        if ($nature = $request->string('nature')->toString()) {
            $query->where('nature', $nature);
        }

        if ($structure = $request->integer('structure_id')) {
            $query->where('organization_unit_id', $structure);
        }

        $status = $request->string('statut')->toString();
        if ($status === 'a_valider') {
            $query->whereIn('status', EbStatus::awaiting());
        } elseif ($status === 'approuvees') {
            $query->whereIn('status', [EbStatus::Approuvee->value, EbStatus::Transformee->value]);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        $request->user()?->restrictOrganization($query, 'organization_unit_id');

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function dashboard(User $user): array
    {
        $query = ExpressionBesoin::query();
        $user->restrictOrganization($query, 'organization_unit_id');
        $all = $query->with('budgetLine.enrichment.tasks', 'engagement')->get();
        $pap = $all->where('nature', BudgetNature::Pap);
        $hors = $all->where('nature', BudgetNature::HorsPap);
        $approved = $all->whereIn('status', [EbStatus::Approuvee, EbStatus::Transformee]);

        return [
            'total' => $all->count(),
            'montant_total' => (int) $all->sum('montant'),
            'pap_count' => $pap->count(),
            'pap_montant' => (int) $pap->sum('montant'),
            'hors_pap_count' => $hors->count(),
            'hors_pap_montant' => (int) $hors->sum('montant'),
            'brouillons' => $all->where('status', EbStatus::Brouillon)->count(),
            'a_valider' => $all->whereIn('status', [EbStatus::Soumise, EbStatus::EnValidation, EbStatus::Validee, EbStatus::EnApprobation])->count(),
            'retournées' => $all->where('status', EbStatus::Retournee)->count(),
            'rejetees' => $all->where('status', EbStatus::Rejetee)->count(),
            'approuvees' => $approved->count(),
            'transformees' => $all->where('status', EbStatus::Transformee)->count(),
            'annulees' => $all->where('status', EbStatus::Annulee)->count(),
            'en_retard' => $all->filter(fn (ExpressionBesoin $eb) => $this->workflow->isLate($eb))->count(),
            'completude_faible' => $all->filter(function (ExpressionBesoin $eb) {
                if ($eb->nature !== BudgetNature::Pap || ! $eb->budgetLine?->enrichment) {
                    return false;
                }

                return $eb->budgetLine->enrichment->completeness()['score'] < 70;
            })->unique('budget_line_id')->count(),
            'propositions_taches' => PapTask::query()->where('proposed', true)->where('validated', false)->count(),
        ];
    }

    private function loadDetail(ExpressionBesoin $eb): ExpressionBesoin
    {
        return $eb->load([
            'exercice',
            'organizationUnit.parent',
            'initiator',
            'budgetLine.enrichment.tasks',
            'lines',
            'imputations.budgetLine',
            'documents',
            'events.actor',
            'engagement',
        ]);
    }

    private function guardEditable(Request $request, ExpressionBesoin $eb): void
    {
        if ($request->user()->id !== $eb->initiator_id || ! $eb->isEditable()) {
            throw ValidationException::withMessages([
                'action' => 'Seul l’initiateur peut modifier un dossier en brouillon ou retourné.',
            ]);
        }
    }
}
