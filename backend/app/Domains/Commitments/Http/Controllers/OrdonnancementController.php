<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Exports\OrdonnancementsExport;
use App\Domains\Commitments\Http\Resources\OrdonnancementResource;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\OrdDelegation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\OrdSuppleance;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\OrdonnancementWorkflow;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Auth\SignatureVerifier;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrdonnancementController extends Controller
{
    public function __construct(private readonly OrdonnancementWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ordonnancement::class);
        $paginator = $this->filtered($request)
            ->with(['liquidation.engagement.expressionBesoin.organizationUnit.parent', 'liquidation.engagement.budgetLine', 'signataire'])
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        return OrdonnancementResource::collection($paginator)
            ->additional(['tableau_de_bord' => $this->dashboard($request->user())])
            ->response();
    }

    public function show(Ordonnancement $ordonnancement): OrdonnancementResource
    {
        $this->authorize('view', $ordonnancement);

        return new OrdonnancementResource($this->loadDetail($ordonnancement));
    }

    public function delegations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ordonnancement::class);
        $montant = $request->integer('montant');
        $authority = $montant > 0 ? $this->workflow->authority($montant) : null;

        return response()->json([
            'data' => OrdDelegation::query()->orderByDesc('starts_on')->get()->map(fn (OrdDelegation $row) => [
                'id' => $row->id,
                'delegant' => $row->delegant,
                'delegataire' => $row->delegataire,
                'fonction' => $row->fonction,
                'seuil_max' => $row->seuil_max,
                'type_depense' => $row->type_depense,
                'debut' => $row->starts_on?->toDateString(),
                'fin' => $row->ends_on?->toDateString(),
                'document' => $row->document,
                'active' => $row->active && $row->starts_on?->lte(now()) && $row->ends_on?->gte(now()),
            ]),
            'seuil_actif' => OrdDelegation::query()->courante()->orderBy('seuil_max')->value('seuil_max'),
            'simulation' => $authority,
            'routages' => Ordonnancement::query()->latest('id')->limit(8)->get()->map(function (Ordonnancement $row) {
                $resolved = $this->workflow->authority((int) $row->montant);

                return [
                    'reference' => $row->reference,
                    'montant' => $row->montant,
                    'ordonnateur_label' => $row->ordonnateur_label,
                    'fondement' => $row->fondement,
                    'coherent' => $row->ordonnateur_role === $resolved['ordonnateur_role'],
                    'regle' => $resolved['seuil'] !== null && (int) $row->montant <= (int) $resolved['seuil'] ? '≤ '.$resolved['seuil'] : '> '.($resolved['seuil'] ?? '—'),
                ];
            }),
            'suppleances' => OrdSuppleance::query()->orderByDesc('starts_on')->get()->map(fn (OrdSuppleance $row) => [
                'id' => $row->id,
                'titulaire' => $row->titulaire,
                'suppleant' => $row->suppleant,
                'debut' => $row->starts_on?->toDateString(),
                'fin' => $row->ends_on?->toDateString(),
                'fondement' => $row->fondement,
                'statut' => $row->statut(),
            ]),
            'peut_parametrer' => $request->user()?->holds('ordonnateur'),
            'peut_suppleer' => in_array($request->user()?->role, ['ordonnateur', 'secretaire_general'], true),
        ]);
    }

    public function storeDelegation(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ordonnancement::class);
        abort_unless($request->user()?->holds('ordonnateur'), 403);
        $data = $request->validate([
            'seuil_max' => ['required', 'integer', 'min:1'],
            'debut' => ['required', 'date'],
            'fin' => ['required', 'date', 'after:debut'],
            'document' => ['required', 'string', 'max:64'],
            'type_depense' => ['nullable', 'string', 'max:120'],
        ]);
        $delegationData = [
            'delegant' => 'Président de la Commission',
            'delegataire' => 'Secrétaire Général',
            'fonction' => 'Ordonnateur délégué',
            'seuil_max' => $data['seuil_max'],
            'starts_on' => $data['debut'],
            'ends_on' => $data['fin'],
            'document' => $data['document'],
            'active' => true,
        ];
        if (filled($data['type_depense'] ?? null)) {
            $delegationData['type_depense'] = $data['type_depense'];
        }
        $delegation = OrdDelegation::query()->create($delegationData);
        if ($delegation->starts_on?->lte(now()) && $delegation->ends_on?->gte(now())) {
            OrdDelegation::query()->whereKeyNot($delegation->id)->where('active', true)->update(['active' => false]);
        }

        return response()->json(['data' => ['id' => $delegation->id, 'document' => $delegation->document]], 201);
    }

    public function storeSuppleance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ordonnancement::class);
        abort_unless(in_array($request->user()?->role, ['ordonnateur', 'secretaire_general'], true), 403);
        $data = $request->validate([
            'titulaire' => ['required', 'string', 'max:120'],
            'suppleant' => ['required', 'string', 'max:120'],
            'debut' => ['required', 'date'],
            'fin' => ['required', 'date', 'after:debut'],
            'fondement' => ['required', 'string', 'max:120'],
        ]);
        $row = OrdSuppleance::query()->create([
            'titulaire' => $data['titulaire'],
            'suppleant' => $data['suppleant'],
            'starts_on' => $data['debut'],
            'ends_on' => $data['fin'],
            'fondement' => $data['fondement'],
            'active' => true,
        ]);

        return response()->json(['data' => ['id' => $row->id, 'statut' => $row->statut()]], 201);
    }

    public function fractionner(Request $request, Ordonnancement $ordonnancement): OrdonnancementResource
    {
        $this->authorize('view', $ordonnancement);
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
        ]);
        $suite = $this->workflow->fractionner($ordonnancement, $request->user(), (int) $data['montant']);

        return new OrdonnancementResource($suite);
    }

    public function sign(Request $request, Ordonnancement $ordonnancement): OrdonnancementResource
    {
        $this->authorize('sign', $ordonnancement);
        $data = $request->validate([
            'confirmation' => ['required', 'accepted'],
            'mot_de_passe' => ['required', 'string', 'max:255'],
        ]);
        app(SignatureVerifier::class)->verify($request->user(), $data['mot_de_passe'], 'ordonnancement', (string) $ordonnancement->id);
        $this->workflow->sign($ordonnancement, $request->user(), (bool) $data['confirmation']);
        $signe = $ordonnancement->fresh();
        $this->archiveOfficial($signe, 'signature', $request->user());
        app(ChainDocumentPublisher::class)->emit($signe, 'bordereau_transmission', $signe->reference, 'transmission', $request->user());

        return new OrdonnancementResource($this->loadDetail($ordonnancement->fresh()));
    }

    public function sendBack(Request $request, Ordonnancement $ordonnancement): OrdonnancementResource
    {
        $this->authorize('sendBack', $ordonnancement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->workflow->sendBack($ordonnancement, $request->user(), $data['motif']);

        return new OrdonnancementResource($this->loadDetail($ordonnancement->fresh()));
    }

    public function reject(Request $request, Ordonnancement $ordonnancement): OrdonnancementResource
    {
        $this->authorize('reject', $ordonnancement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $this->workflow->reject($ordonnancement, $request->user(), $data['motif']);

        return new OrdonnancementResource($this->loadDetail($ordonnancement->fresh()));
    }

    public function reprendre(Request $request, Ordonnancement $ordonnancement): OrdonnancementResource
    {
        $this->authorize('reprendre', $ordonnancement);
        $this->workflow->reprendre($ordonnancement, $request->user());
        $repris = $ordonnancement->fresh();
        app(ChainDocumentPublisher::class)->emit($repris, 'bordereau_transmission', $repris->reference, 'transmission', $request->user());

        return new OrdonnancementResource($this->loadDetail($repris));
    }

    public function pdf(Request $request, Ordonnancement $ordonnancement): StreamedResponse
    {
        $this->authorize('view', $ordonnancement);
        $kind = $request->string('document')->toString() ?: 'ordonnancement';
        abort_unless(in_array($kind, ['ordonnancement', 'bordereau_transmission'], true), 404);
        $publisher = app(ChainDocumentPublisher::class);
        $document = $request->filled('version')
            ? $publisher->current($ordonnancement, $kind, $request->integer('version'))
            : ($publisher->current($ordonnancement, $kind) ?? $publisher->emit($ordonnancement, $kind, $ordonnancement->reference, 'premiere_consultation', $request->user(), quietly: false));
        abort_if($document === null, 404, 'Version introuvable.');

        return app(OfficialDocumentService::class)->download($document);
    }

    private function archiveOfficial(Ordonnancement $ordonnancement, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        return app(ChainDocumentPublisher::class)->emit($ordonnancement, 'ordonnancement', $ordonnancement->reference, $event, $actor, $quietly);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Ordonnancement::class);

        return Excel::download(new OrdonnancementsExport(
            $this->filtered($request)->with(['liquidation.engagement.expressionBesoin', 'liquidation.engagement.budgetLine'])->get()
        ), 'ordonnancements.xlsx');
    }

    private function loadDetail(Ordonnancement $ordonnancement): Ordonnancement
    {
        return $ordonnancement->load([
            'liquidation.engagement.expressionBesoin.organizationUnit.parent',
            'liquidation.engagement.expressionBesoin.imputations.budgetLine',
            'liquidation.engagement.expressionBesoin.documents',
            'liquidation.engagement.budgetLine.enrichment',
            'liquidation.engagement.liquidations.ordonnancement',
            'liquidation.certifiedBy',
            'signataire',
            'paiement',
            'events.actor',
        ]);
    }

    /**
     * @return Builder<Ordonnancement>
     */
    private function filtered(Request $request): Builder
    {
        return Ordonnancement::query()
            ->when($request->string('statut')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $query->where(function (Builder $inner) use ($term) {
                    $inner->where('reference', 'like', '%'.$term.'%')
                        ->orWhere('ordonnateur_label', 'like', '%'.$term.'%')
                        ->orWhereHas('liquidation', fn (Builder $liquidation) => $liquidation
                            ->where('reference', 'like', '%'.$term.'%')
                            ->orWhere('fournisseur', 'like', '%'.$term.'%'));
                });
            })
            ->when($request->string('nature')->toString(), fn (Builder $query, string $nature) => $query->whereHas(
                'liquidation.engagement.expressionBesoin',
                fn (Builder $eb) => $eb->where('nature', $nature),
            ))
            ->when($request->string('ligne')->toString(), fn (Builder $query, string $ligne) => $query->whereHas(
                'liquidation.engagement.budgetLine',
                fn (Builder $line) => $line->where('code', $ligne),
            ))
            ->when($request->date('du'), fn (Builder $query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($request->date('au'), fn (Builder $query, $until) => $query->whereDate('created_at', '<=', $until))
            ->when($request->user()?->organizationScopeIds(), fn (Builder $query, array $ids) => $query->whereHas(
                'liquidation.engagement.expressionBesoin',
                fn (Builder $eb) => $eb->whereIn('organization_unit_id', $ids),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(User $user): array
    {
        $rows = Ordonnancement::query()->with(['liquidation', 'paiement'])->get();
        $signed = $rows->filter(fn (Ordonnancement $row) => $row->status?->signed() === true);
        $liquide = (int) Liquidation::query()
            ->whereNotIn('status', ['rejetee', 'annulee'])
            ->sum('montant_net');
        $ordonnance = (int) $signed->sum('montant');
        $signedRoles = $signed->groupBy(fn (Ordonnancement $row) => (string) $row->ordonnateur_role);
        $vote = (int) BudgetLine::query()->officielle()->sum('montant_vote');
        $engage = (int) Engagement::query()->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])->sum('montant');
        $paye = (int) Paiement::query()->sum('montant');
        $attente = $rows->where('status', OrdonnancementStatus::ASigner);
        $avecVisa = $rows->filter(fn (Ordonnancement $row) => $row->signed_at !== null && $row->liquidation?->vised_at !== null);
        $delaiVisa = $avecVisa->isEmpty() ? null : $avecVisa->avg(fn (Ordonnancement $row) => abs($row->liquidation->vised_at->diffInHours($row->signed_at)) / 24);
        $avecPaiement = $rows->filter(fn (Ordonnancement $row) => $row->signed_at !== null && $row->paiement?->created_at !== null);
        $delaiTransmission = $avecPaiement->isEmpty() ? null : $avecPaiement->avg(fn (Ordonnancement $row) => abs($row->signed_at->diffInMinutes($row->paiement->created_at)));
        $delegation = OrdDelegation::query()->courante()->orderBy('seuil_max')->first();
        $alertes = [];
        $enRetard = $attente->filter(fn (Ordonnancement $row) => $row->created_at?->lt(now()->subHours(48)))->count();
        if ($enRetard > 0) {
            $alertes[] = $enRetard.' ordre(s) de paiement en attente de signature depuis plus de 48 heures';
        }
        $erreurs = $rows->where('status', OrdonnancementStatus::TransmissionErreur)->count();
        if ($erreurs > 0) {
            $alertes[] = $erreurs.' transmission(s) à l’Agence Comptable en erreur';
        }
        if ($attente->isNotEmpty()) {
            $alertes[] = $attente->count().' dossier(s) à signer sans coordonnées bancaires dans le référentiel des tiers';
        }
        if ($delegation !== null) {
            $alertes[] = 'Délégation '.$delegation->document.' valide jusqu’au '.$delegation->ends_on?->format('d/m/Y');
        }

        return [
            'total' => $rows->count(),
            'a_signer' => $attente->count(),
            'signes_aujourdhui' => $rows->filter(fn (Ordonnancement $row) => $row->signed_at?->isToday())->count(),
            'montant_signes_aujourdhui' => (int) $rows->filter(fn (Ordonnancement $row) => $row->signed_at?->isToday())->sum('montant'),
            'transmission_erreur' => $erreurs,
            'retournes' => $rows->where('status', OrdonnancementStatus::Retourne)->count(),
            'rejetes' => $rows->where('status', OrdonnancementStatus::Rejete)->count(),
            'transformes' => $rows->where('status', OrdonnancementStatus::TransformePaiement)->count(),
            'montant_a_signer' => (int) $attente->sum('montant'),
            'montant_ordonnance' => $ordonnance,
            'taux' => $liquide > 0 ? round($ordonnance / $liquide * 100, 1) : 0,
            'execution' => [
                'vote' => $vote,
                'engage' => $engage,
                'liquide' => $liquide,
                'ordonnance' => $ordonnance,
                'paye' => $paye,
            ],
            'repartition' => [
                'president' => (int) ($signedRoles->get('ordonnateur')?->sum('montant') ?? 0),
                'president_nombre' => $signedRoles->get('ordonnateur')?->count() ?? 0,
                'delegue' => (int) ($signedRoles->get('secretaire_general')?->sum('montant') ?? 0),
                'delegue_nombre' => $signedRoles->get('secretaire_general')?->count() ?? 0,
            ],
            'delais' => [
                'visa_signature' => $delaiVisa !== null ? round((float) $delaiVisa, 1) : null,
                'attente' => $attente->isEmpty() ? null : round((float) $attente->avg(fn (Ordonnancement $row) => abs($row->created_at->diffInHours(now())) / 24), 1),
                'transmission_minutes' => $delaiTransmission !== null ? (int) round((float) $delaiTransmission) : null,
                'taux_retour' => $rows->count() > 0 ? round($rows->where('status', OrdonnancementStatus::Retourne)->count() / $rows->count() * 100, 1) : 0,
            ],
            'alertes' => $alertes,
            'compteurs' => [
                ['statut' => '', 'libelle' => 'Générés', 'valeur' => $rows->count()],
                ['statut' => 'a_signer', 'libelle' => 'À signer', 'valeur' => $attente->count()],
                ['statut' => 'retourne', 'libelle' => 'Retournés', 'valeur' => $rows->where('status', OrdonnancementStatus::Retourne)->count()],
                ['statut' => 'rejete', 'libelle' => 'Rejetés', 'valeur' => $rows->where('status', OrdonnancementStatus::Rejete)->count()],
                ['statut' => 'signe', 'libelle' => 'Signés', 'valeur' => $rows->filter(fn (Ordonnancement $row) => $row->status === OrdonnancementStatus::Signe)->count()],
                ['statut' => 'transmission_erreur', 'libelle' => 'Transmission en erreur', 'valeur' => $erreurs],
                ['statut' => 'transforme_paiement', 'libelle' => 'Transmis à l’Agence Comptable', 'valeur' => $rows->where('status', OrdonnancementStatus::TransformePaiement)->count()],
                ['statut' => '', 'libelle' => 'Pris en charge', 'valeur' => 0],
            ],
            'taches' => $rows
                ->filter(fn (Ordonnancement $row) => $row->ordonnateur_role === $user->role && in_array($row->status, [OrdonnancementStatus::ASigner, OrdonnancementStatus::TransmissionErreur], true))
                ->map(fn (Ordonnancement $row) => [
                    'id' => $row->id,
                    'reference' => $row->reference,
                    'montant' => $row->montant,
                    'action' => $row->status === OrdonnancementStatus::TransmissionErreur ? 'Reprendre la transmission' : 'Signer',
                ])
                ->values(),
        ];
    }
}
