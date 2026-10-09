<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Exports\LiquidationsExport;
use App\Domains\Commitments\Http\Resources\LiquidationResource;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\LiquidationWorkflow;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiquidationController extends Controller
{
    public function __construct(private readonly LiquidationWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Liquidation::class);
        $paginator = $this->filtered($request)
            ->with(['engagement.expressionBesoin.organizationUnit.parent', 'engagement.budgetLine', 'certifiedBy'])
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        return LiquidationResource::collection($paginator)
            ->additional(['tableau_de_bord' => $this->dashboard($request->user())])
            ->response();
    }

    public function show(Liquidation $liquidation): LiquidationResource
    {
        $this->authorize('view', $liquidation);

        return new LiquidationResource($this->loadDetail($liquidation));
    }

    public function certify(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $this->authorize('certify', $liquidation);
        $data = $request->validate([
            'montant_accepte' => ['required', 'integer', 'min:0'],
            'reserves' => ['nullable', 'string'],
            'date_service' => ['nullable', 'date'],
            'bon_livraison' => ['nullable', 'string', 'max:64'],
            'nature_prestation' => ['nullable', 'string', 'max:64'],
            'lieu_reception' => ['nullable', 'string', 'max:255'],
            'lignes' => ['nullable', 'array'],
            'lignes.*.designation' => ['required', 'string', 'max:255'],
            'lignes.*.quantite_commandee' => ['required', 'numeric', 'min:0'],
            'lignes.*.quantite_livree' => ['required', 'numeric', 'min:0'],
            'lignes.*.quantite_acceptee' => ['required', 'numeric', 'min:0'],
            'lignes.*.prix_unitaire' => ['required', 'integer', 'min:0'],
            'lignes.*.observation' => ['nullable', 'string'],
        ]);
        $this->workflow->certify($liquidation, $request->user(), $data['montant_accepte'], $data['reserves'] ?? null, [
            'date_service' => $data['date_service'] ?? null,
            'bon_livraison' => $data['bon_livraison'] ?? null,
            'nature_prestation' => $data['nature_prestation'] ?? null,
            'lieu_reception' => $data['lieu_reception'] ?? null,
            'lignes' => $data['lignes'] ?? null,
        ]);
        $certifiee = $liquidation->fresh();
        $publisher = app(ChainDocumentPublisher::class);
        $publisher->emit($certifiee, 'attestation_service_fait', $certifiee->reference, 'service_fait', $request->user());
        $publisher->emit($certifiee, 'pv_reception', $certifiee->reference, 'reception', $request->user());

        return new LiquidationResource($this->loadDetail($certifiee));
    }

    public function invoice(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $this->authorize('invoice', $liquidation);
        $data = $request->validate([
            'numero' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date'],
            'echeance' => ['nullable', 'date'],
            'montant_ht' => ['required', 'integer', 'min:1'],
            'taxes' => ['required', 'integer', 'min:0'],
            'retenue' => ['nullable', 'integer', 'min:0'],
            'penalite' => ['nullable', 'integer', 'min:0'],
        ]);
        $this->workflow->saveInvoice($liquidation, $request->user(), [
            'numero' => $data['numero'],
            'date' => $data['date'],
            'echeance' => $data['echeance'] ?? null,
            'montant_ht' => $data['montant_ht'],
            'taxes' => $data['taxes'],
            'retenue' => $data['retenue'] ?? 0,
            'penalite' => $data['penalite'] ?? 0,
        ]);

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function submit(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $this->authorize('submit', $liquidation);
        $this->workflow->submit($liquidation, $request->user());

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function requestDuplicate(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $this->authorize('requestDuplicate', $liquidation);
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);
        $this->workflow->requestDuplicateReview($liquidation, $request->user(), $data['motif']);

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function sendBack(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ]);
        $this->authorize('sendBack', $liquidation);
        $this->workflow->sendBack($liquidation, $request->user(), $data['motif'], $data['observations'] ?? null);

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function complement(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ]);
        $this->authorize('complement', $liquidation);
        $this->workflow->requestComplement($liquidation, $request->user(), $data['motif'], $data['observations'] ?? null);

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function reject(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ]);
        $this->authorize('reject', $liquidation);
        $this->workflow->reject($liquidation, $request->user(), $data['motif'], $data['observations'] ?? null);

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function vise(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $this->authorize('vise', $liquidation);
        $data = $request->validate(['observations' => ['nullable', 'string']]);
        $this->workflow->vise($liquidation, $request->user(), $data['observations'] ?? null);
        $this->archiveOfficial($liquidation->fresh(), 'visa', $request->user());

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function rectify(Request $request, Liquidation $liquidation): LiquidationResource
    {
        $data = $request->validate([
            'kind' => ['required', 'in:avoir,complementaire'],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $this->authorize('rectify', $liquidation);
        $this->workflow->rectify($liquidation, $request->user(), $data['kind'], (int) $data['montant'], $data['motif']);
        $this->archiveOfficial($liquidation->fresh(), 'rectification', $request->user());

        return new LiquidationResource($this->loadDetail($liquidation->fresh()));
    }

    public function openNext(Request $request, Engagement $engagement): JsonResponse
    {
        $this->authorize('viewAny', Liquidation::class);
        $liquidation = $this->workflow->openNext($engagement, $request->user());

        return (new LiquidationResource($this->loadDetail($liquidation)))
            ->response()
            ->setStatusCode(201);
    }

    public function pdf(Request $request, Liquidation $liquidation): StreamedResponse
    {
        $this->authorize('pdf', $liquidation);
        $kind = $request->string('document')->toString() ?: 'liquidation';
        abort_unless(in_array($kind, ['liquidation', 'attestation_service_fait', 'pv_reception'], true), 404);
        $publisher = app(ChainDocumentPublisher::class);
        $document = $request->filled('version')
            ? $publisher->current($liquidation, $kind, $request->integer('version'))
            : ($publisher->current($liquidation, $kind) ?? $publisher->emit($liquidation, $kind, $liquidation->reference, 'premiere_consultation', $request->user(), quietly: false));
        abort_if($document === null, 404, 'Version introuvable.');

        return app(OfficialDocumentService::class)->download($document);
    }

    private function archiveOfficial(Liquidation $liquidation, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        return app(ChainDocumentPublisher::class)->emit($liquidation, 'liquidation', $liquidation->reference, $event, $actor, $quietly);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Liquidation::class);

        return Excel::download(
            new LiquidationsExport($this->filtered($request)->with(['engagement.expressionBesoin', 'engagement.budgetLine'])->get()),
            'liquidations.xlsx',
        );
    }

    private function filtered(Request $request)
    {
        $query = Liquidation::query();
        $request->user()?->restrictOrganizationThrough($query, 'engagement.expressionBesoin');
        if ($status = $request->string('statut')->toString()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('q')->toString()) {
            $query->where(function ($inner) use ($search) {
                $inner->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('fournisseur', 'like', '%'.$search.'%')
                    ->orWhere('invoice_number', 'like', '%'.$search.'%')
                    ->orWhereHas('engagement', function ($engagement) use ($search) {
                        $engagement->where('reference', 'like', '%'.$search.'%')
                            ->orWhereHas('expressionBesoin', fn ($eb) => $eb->where('objet', 'like', '%'.$search.'%')->orWhere('reference', 'like', '%'.$search.'%'));
                    });
            });
        }

        if ($ids = $request->user()?->organizationScopeIds()) {
            $query->whereHas('engagement.expressionBesoin', fn ($eb) => $eb->whereIn('organization_unit_id', $ids));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(?User $user): array
    {
        $counts = Liquidation::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $active = Liquidation::query()->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value]);
        $engaged = (int) Engagement::query()->whereNotNull('liquidation_reference')->sum('montant');
        $liquidated = (int) (clone $active)->sum('montant_brut');

        $tasks = [];
        if ($user) {
            $tasks = Liquidation::query()
                ->with(['engagement.expressionBesoin'])
                ->where('workflow_step', $user->holds('controleur_financier') ? 'controleur_financier' : 'initiateur')
                ->when(! $user->holds('controleur_financier'), fn ($query) => $query->whereHas('engagement.expressionBesoin', fn ($eb) => $eb->where('initiator_id', $user->id)))
                ->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value, LiquidationStatus::TransformeeOrdonnancement->value])
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Liquidation $row) => [
                    'id' => $row->id,
                    'reference' => $row->reference,
                    'objet' => $row->engagement?->expressionBesoin?->objet,
                    'fournisseur' => $row->fournisseur,
                    'montant_net' => $row->montant_net,
                    'action' => $row->service_fait_at === null ? 'Certifier le service fait' : ($row->invoice_number === null ? 'Saisir la facture' : 'Traiter le dossier'),
                ]);
        }

        return [
            'total' => (int) $counts->sum(),
            'montant' => (int) (clone $active)->sum('montant_net'),
            'restant' => max(0, $engaged - $liquidated),
            'generees' => (int) ($counts[LiquidationStatus::Generee->value] ?? 0),
            'preparation' => (int) ($counts[LiquidationStatus::EnPreparation->value] ?? 0),
            'controle' => (int) ($counts[LiquidationStatus::EnControle->value] ?? 0),
            'complements' => (int) ($counts[LiquidationStatus::Complement->value] ?? 0) + (int) ($counts[LiquidationStatus::Retournee->value] ?? 0),
            'rejetees' => (int) ($counts[LiquidationStatus::Rejetee->value] ?? 0),
            'transformees' => (int) ($counts[LiquidationStatus::TransformeeOrdonnancement->value] ?? 0),
            'en_retard' => Liquidation::query()->whereNotNull('due_on')->whereDate('due_on', '<', now())->whereNotIn('status', [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value, LiquidationStatus::TransformeeOrdonnancement->value])->count(),
            'taches' => $tasks,
        ];
    }

    private function loadDetail(Liquidation $liquidation): Liquidation
    {
        return $liquidation->load([
            'engagement.expressionBesoin.organizationUnit.parent',
            'engagement.expressionBesoin.lines.task',
            'engagement.expressionBesoin.imputations.budgetLine',
            'engagement.expressionBesoin.documents',
            'engagement.budgetLine',
            'engagement.liquidations',
            'certifiedBy.organizationUnit',
            'events.actor',
            'ordonnancement.paiement',
            'rectifications',
        ]);
    }
}
