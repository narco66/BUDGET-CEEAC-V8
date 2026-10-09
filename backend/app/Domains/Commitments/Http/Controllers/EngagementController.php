<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Exports\EngagementsExport;
use App\Domains\Commitments\Http\Resources\EngagementResource;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\EngEvent;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\EngagementWorkflow;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EngagementController extends Controller
{
    public function __construct(private readonly EngagementWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Engagement::class);
        $paginator = $this->filtered($request)
            ->with(['expressionBesoin.organizationUnit.parent', 'budgetLine'])
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        return EngagementResource::collection($paginator)
            ->additional(['tableau_de_bord' => $this->dashboard()])
            ->response();
    }

    public function show(Engagement $engagement): EngagementResource
    {
        $this->authorize('view', $engagement);

        return new EngagementResource($this->loadDetail($engagement));
    }

    public function update(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('update', $engagement);
        $data = $request->validate([
            'tiers_id' => ['nullable', 'integer', 'exists:tiers,id'],
            'beneficiaire' => ['required_without:tiers_id', 'nullable', 'string', 'max:255'],
            'rccm' => ['nullable', 'string', 'max:64'],
            'nif' => ['nullable', 'string', 'max:64'],
        ]);
        $this->workflow->updateBeneficiary(
            $engagement,
            $request->user(),
            $data['beneficiaire'] ?? '',
            $data['rccm'] ?? null,
            $data['nif'] ?? null,
            isset($data['tiers_id']) ? (int) $data['tiers_id'] : null,
        );

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function transmit(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('transmit', $engagement);
        $data = $request->validate(['observations' => ['nullable', 'string']]);
        $this->workflow->transmit($engagement, $request->user(), $data['observations'] ?? null);

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function sendBack(Request $request, Engagement $engagement): EngagementResource
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ]);
        $this->authorize('sendBack', $engagement);
        $this->workflow->sendBack($engagement, $request->user(), $data['motif'], $data['observations'] ?? null);

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function reject(Request $request, Engagement $engagement): EngagementResource
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ]);
        $this->authorize('reject', $engagement);
        $this->workflow->reject($engagement, $request->user(), $data['motif'], $data['observations'] ?? null);

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function storePiece(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('view', $engagement);
        abort_unless($request->user()->holds('expert_budget'), 403);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'fichier' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', config('ged.extensions')), 'mimes:'.implode(',', config('ged.extensions'))],
        ]);
        $this->workflow->joindrePiece($engagement, $request->user(), $data['type'], $request->file('fichier'));

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function vise(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('vise', $engagement);
        $data = $request->validate(['observations' => ['nullable', 'string']]);
        $this->workflow->vise($engagement, $request->user(), $data['observations'] ?? null);
        $vise = $engagement->fresh();
        $this->archiveOfficial($vise, 'visa', $request->user());
        app(ChainDocumentPublisher::class)->emit($vise, 'controle_budgetaire', $vise->reference, 'visa', $request->user());

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function degager(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('view', $engagement);
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
            'acte' => ['nullable', 'string', 'max:100'],
        ]);
        $this->workflow->degager($engagement, $request->user(), (int) $data['montant'], $data['motif'], $data['acte'] ?? null);
        $this->archiveOfficial($engagement->fresh(), 'degagement', $request->user());

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function partiel(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('view', $engagement);
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $suite = $this->workflow->engagerPartiellement($engagement, $request->user(), (int) $data['montant'], $data['motif']);

        return new EngagementResource($this->loadDetail($suite->fresh()));
    }

    public function avenant(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('view', $engagement);
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $suite = $this->workflow->ouvrirAvenant($engagement, $request->user(), (int) $data['montant'], $data['motif']);

        return new EngagementResource($this->loadDetail($suite));
    }

    public function annuler(Request $request, Engagement $engagement): EngagementResource
    {
        $this->authorize('view', $engagement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->workflow->annuler($engagement, $request->user(), $data['motif']);

        return new EngagementResource($this->loadDetail($engagement->fresh()));
    }

    public function pdf(Request $request, Engagement $engagement): StreamedResponse
    {
        $this->authorize('pdf', $engagement);
        $kind = $request->string('document')->toString() ?: 'engagement';
        abort_unless(in_array($kind, ['engagement', 'controle_budgetaire'], true), 404);
        $publisher = app(ChainDocumentPublisher::class);
        $document = $request->filled('version')
            ? $publisher->current($engagement, $kind, $request->integer('version'))
            : ($publisher->current($engagement, $kind) ?? $publisher->emit($engagement, $kind, $engagement->reference, 'premiere_consultation', $request->user(), quietly: false));
        abort_if($document === null, 404, 'Version introuvable.');

        return app(OfficialDocumentService::class)->download($document);
    }

    private function archiveOfficial(Engagement $engagement, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        return app(ChainDocumentPublisher::class)->emit($engagement, 'engagement', $engagement->reference, $event, $actor, $quietly);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Engagement::class);

        return Excel::download(
            new EngagementsExport($this->filtered($request)->with(['expressionBesoin.organizationUnit', 'budgetLine'])->get()),
            'engagements.xlsx',
        );
    }

    private function filtered(Request $request)
    {
        $query = Engagement::query();
        $request->user()?->restrictOrganizationThrough($query, 'expressionBesoin');
        if ($status = $request->string('statut')->toString()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('q')->toString()) {
            $query->where(function ($inner) use ($search) {
                $inner->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('beneficiary_name', 'like', '%'.$search.'%')
                    ->orWhereHas('expressionBesoin', fn ($eb) => $eb->where('reference', 'like', '%'.$search.'%')->orWhere('objet', 'like', '%'.$search.'%'));
            });
        }
        $ids = $request->user()?->organizationScopeIds();
        if ($ids !== null) {
            $query->whereHas('expressionBesoin', fn ($eb) => $eb->whereIn('organization_unit_id', $ids));
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function dashboard(): array
    {
        $counts = Engagement::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'montant' => (int) Engagement::query()->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])->sum('montant'),
            'instruction' => (int) ($counts[EngagementStatus::EnInstruction->value] ?? 0) + (int) ($counts[EngagementStatus::Retourne->value] ?? 0),
            'a_valider' => (int) ($counts[EngagementStatus::AValider->value] ?? 0),
            'controle' => (int) ($counts[EngagementStatus::EnControle->value] ?? 0),
            'retournes' => (int) ($counts[EngagementStatus::Retourne->value] ?? 0),
            'rejetes' => (int) ($counts[EngagementStatus::Rejete->value] ?? 0),
            'vises' => Engagement::query()->whereNotNull('visa_reference')->count(),
            'transformes' => (int) ($counts[EngagementStatus::TransformeLiquidation->value] ?? 0),
            'delais' => $this->averageDelays(),
        ];
    }

    /**
     * @return list<array{etape: string, jours: float}>
     */
    private function averageDelays(): array
    {
        $labels = [
            'en_instruction' => 'Instruction',
            'a_valider' => 'Validation',
            'en_controle' => 'Contrôle CF',
        ];
        $sums = [];
        $counts = [];
        $previous = [];
        $events = EngEvent::query()->orderBy('engagement_id')->orderBy('id')->get(['engagement_id', 'to_status', 'created_at']);
        foreach ($events as $event) {
            $key = $event->engagement_id;
            if (isset($previous[$key], $labels[$previous[$key]['status']])) {
                $days = abs($previous[$key]['at']->diffInHours($event->created_at)) / 24;
                $status = $previous[$key]['status'];
                $sums[$status] = ($sums[$status] ?? 0) + $days;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $previous[$key] = ['status' => $event->to_status, 'at' => $event->created_at];
        }

        return collect($labels)->map(fn (string $label, string $status) => [
            'etape' => $label,
            'jours' => isset($counts[$status]) ? round($sums[$status] / $counts[$status], 1) : 0,
        ])->values()->all();
    }

    private function loadDetail(Engagement $engagement): Engagement
    {
        return $engagement->load([
            'expressionBesoin.organizationUnit.parent',
            'expressionBesoin.lines',
            'expressionBesoin.imputations.budgetLine',
            'expressionBesoin.documents',
            'budgetLine.enrichment',
            'events.actor',
            'liquidations.ordonnancement.paiement',
            'degagements.actor',
        ]);
    }
}
