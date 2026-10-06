<?php

namespace App\Domains\Commitments\Http\Controllers;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Exports\PaiementsExport;
use App\Domains\Commitments\Http\Resources\PaiementResource;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PayLot;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\PaiementWorkflow;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Domains\Suppliers\Services\TiersService;
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

class PaiementController extends Controller
{
    public function __construct(private readonly PaiementWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Paiement::class);
        $paginator = $this->filtered($request)
            ->with(['ordonnancement.liquidation.engagement.expressionBesoin.organizationUnit.parent', 'ordonnancement.liquidation.engagement.budgetLine', 'lot'])
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        return PaiementResource::collection($paginator)
            ->additional(['tableau_de_bord' => $this->dashboard()])
            ->response();
    }

    public function show(Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);

        return new PaiementResource($this->loadDetail($paiement));
    }

    public function prendreEnCharge(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);

        return new PaiementResource($this->loadDetail($this->workflow->prendreEnCharge($paiement, $request->user())));
    }

    public function preparer(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate([
            'mode' => ['required', 'in:virement,cheque,caisse'],
            'compte_bancaire_id' => ['nullable', 'integer'],
            'compte_ceeac' => ['nullable', 'string', 'max:64'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        return new PaiementResource($this->loadDetail($this->workflow->preparer($paiement, $request->user(), $data)));
    }

    /**
     * Comptes validés du tiers attendu, seuls utilisables pour un virement
     * ou un chèque.
     */
    public function eligibleAccounts(Paiement $paiement): JsonResponse
    {
        $this->authorize('view', $paiement);
        $service = app(TiersService::class);
        $tiers = $service->expectedTiers($paiement->load('ordonnancement.liquidation.engagement'));

        return response()->json([
            'tiers' => $tiers ? ['id' => $tiers->id, 'code' => $tiers->code, 'raison_sociale' => $tiers->raison_sociale, 'statut' => $tiers->status] : null,
            'data' => $service->eligibleAccounts($paiement)->map(fn (TiersBankAccount $account) => [
                'id' => $account->id,
                'banque' => $account->banque,
                'agence' => $account->agence,
                'numero_masque' => $account->maskedNumber(),
                'titulaire' => $account->titulaire,
                'devise' => $account->devise,
                'vigilance' => $account->isUnderVigilance(),
                'valide_le' => $account->validated_at?->toDateString(),
            ])->values(),
        ]);
    }

    public function soumettre(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);

        return new PaiementResource($this->loadDetail($this->workflow->soumettre($paiement, $request->user())));
    }

    public function valider(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);

        return new PaiementResource($this->loadDetail($this->workflow->valider($paiement, $request->user())));
    }

    public function signer(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate([
            'confirmation' => ['required', 'accepted'],
            'mot_de_passe' => ['required', 'string', 'max:255'],
        ]);
        app(SignatureVerifier::class)->verify($request->user(), $data['mot_de_passe'], 'paiement', (string) $paiement->id);

        return new PaiementResource($this->loadDetail($this->workflow->signer($paiement, $request->user(), (bool) $data['confirmation'])));
    }

    public function retourner(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        return new PaiementResource($this->loadDetail($this->workflow->retourner($paiement, $request->user(), $data['motif'])));
    }

    public function rejeter(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        return new PaiementResource($this->loadDetail($this->workflow->rejeter($paiement, $request->user(), $data['motif'])));
    }

    public function suspendre(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        return new PaiementResource($this->loadDetail($this->workflow->suspendre($paiement, $request->user(), $data['motif'])));
    }

    public function executer(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:64'],
            'date_valeur' => ['required', 'date'],
            'preuve' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $payeAvant = (int) $paiement->montant_paye;
        $paiement = $this->workflow->executer($paiement, $request->user(), $data['montant'], $data['reference'], $data['date_valeur'], $request->file('preuve'));
        if ((int) $paiement->montant_paye !== $payeAvant) {
            $this->archiveOfficial($paiement, 'execution '.$data['reference'], $request->user());
            $this->archiveMode($paiement, 'execution '.$data['reference'], $request->user());
        }

        return new PaiementResource($this->loadDetail($paiement));
    }

    public function rapprocher(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate(['reference' => ['required', 'string', 'max:64']]);
        $paiement = $this->workflow->rapprocher($paiement, $request->user(), $data['reference']);
        app(ChainDocumentPublisher::class)->emit($paiement, 'rapprochement', $paiement->reference, 'rapprochement', $request->user());

        return new PaiementResource($this->loadDetail($paiement));
    }

    public function rejetBancaire(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'execution_id' => ['nullable', 'integer'],
        ]);

        return new PaiementResource($this->loadDetail(
            $this->workflow->rejetBancaire($paiement, $request->user(), $data['motif'], $data['execution_id'] ?? null)
        ));
    }

    public function reemettre(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        return new PaiementResource($this->loadDetail($this->workflow->reemettre($paiement, $request->user(), $data['motif'])));
    }

    public function leverSuspension(Request $request, Paiement $paiement): PaiementResource
    {
        $this->authorize('view', $paiement);
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        return new PaiementResource($this->loadDetail($this->workflow->leverSuspension($paiement, $request->user(), $data['motif'])));
    }

    public function lots(): JsonResponse
    {
        $this->authorize('viewAny', Paiement::class);

        return response()->json([
            'data' => PayLot::query()->with('paiements')->latest('id')->get()->map(fn (PayLot $lot) => [
                'id' => $lot->id,
                'reference' => $lot->reference,
                'libelle' => $lot->libelle,
                'statut' => $lot->status,
                'compte_debiteur' => $lot->compte_debiteur,
                'montant' => $lot->montant,
                'reference_reglement' => $lot->reference_reglement,
                'date_valeur' => $lot->date_valeur?->toDateString(),
                'paiements' => $lot->paiements->map(fn (Paiement $row) => [
                    'id' => $row->id,
                    'reference' => $row->reference,
                    'montant' => $row->montant,
                    'statut' => $row->status?->value,
                    'beneficiaire' => $row->titulaire,
                ]),
            ]),
            'eligibles' => Paiement::query()
                ->where('status', PaiementStatus::Autorise)
                ->where('mode', 'virement')
                ->whereNull('lot_id')
                ->get(['id', 'reference', 'montant', 'titulaire', 'compte_ceeac']),
        ]);
    }

    public function storeLot(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Paiement::class);
        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:160'],
            'paiements' => ['required', 'array', 'min:2'],
            'paiements.*' => ['integer'],
        ]);
        $lot = $this->workflow->ouvrirLot($request->user(), $data['libelle'], $data['paiements']);

        return response()->json(['reference' => $lot->reference, 'montant' => $lot->montant], 201);
    }

    public function executerLot(Request $request, PayLot $lot): JsonResponse
    {
        $this->authorize('viewAny', Paiement::class);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'date_valeur' => ['required', 'date'],
            'preuve' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $lot = $this->workflow->executerLot($lot, $request->user(), $data['reference'], $data['date_valeur'], $request->file('preuve'));
        foreach ($lot->paiements as $paiement) {
            $this->archiveOfficial($paiement, 'execution '.$data['reference'], $request->user());
            $this->archiveMode($paiement, 'execution '.$data['reference'], $request->user());
        }

        return response()->json(['reference' => $lot->reference, 'statut' => $lot->status]);
    }

    public function pdf(Request $request, Paiement $paiement): StreamedResponse
    {
        $this->authorize('view', $paiement);
        $kind = $request->string('document')->toString() ?: 'paiement';
        abort_unless(in_array($kind, ['paiement', 'ordre_virement', 'bordereau_cheque', 'bon_sortie_caisse', 'rapprochement'], true), 404);
        $publisher = app(ChainDocumentPublisher::class);
        $document = $request->filled('version')
            ? $publisher->current($paiement, $kind, $request->integer('version'))
            : ($publisher->current($paiement, $kind) ?? $publisher->emit($paiement, $kind, $paiement->reference, 'premiere_consultation', $request->user(), quietly: false));
        abort_if($document === null, 404, 'Version introuvable.');

        return app(OfficialDocumentService::class)->download($document);
    }

    private function archiveOfficial(Paiement $paiement, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        return app(ChainDocumentPublisher::class)->emit($paiement, 'paiement', $paiement->reference, $event, $actor, $quietly);
    }

    private function archiveMode(Paiement $paiement, string $event, ?User $actor): void
    {
        $kind = match ($paiement->mode) {
            'virement' => 'ordre_virement',
            'cheque' => 'bordereau_cheque',
            'caisse' => 'bon_sortie_caisse',
            default => null,
        };
        if ($kind === null) {
            return;
        }
        app(ChainDocumentPublisher::class)->emit($paiement, $kind, $paiement->reference, $event, $actor);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Paiement::class);

        return Excel::download(new PaiementsExport(
            $this->filtered($request)->with(['ordonnancement.liquidation.engagement.expressionBesoin'])->get()
        ), 'paiements.xlsx');
    }

    private function loadDetail(Paiement $paiement): Paiement
    {
        return $paiement->load([
            'ordonnancement.liquidation.engagement.expressionBesoin.organizationUnit.parent',
            'ordonnancement.liquidation.engagement.expressionBesoin.documents',
            'ordonnancement.liquidation.engagement.budgetLine.enrichment',
            'chargePar',
            'signataire',
            'lot',
            'events.actor',
            'executions.actor',
            'bankAccount.tiers',
            'bankAccount.validatedBy',
        ]);
    }

    /**
     * @return Builder<Paiement>
     */
    private function filtered(Request $request): Builder
    {
        return Paiement::query()
            ->when($request->string('statut')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->string('mode')->toString(), fn (Builder $query, string $mode) => $query->where('mode', $mode))
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $query->where(function (Builder $inner) use ($term) {
                    $inner->where('reference', 'like', '%'.$term.'%')
                        ->orWhere('titulaire', 'like', '%'.$term.'%')
                        ->orWhereHas('ordonnancement', fn (Builder $ordre) => $ordre
                            ->where('reference', 'like', '%'.$term.'%')
                            ->orWhereHas('liquidation', fn (Builder $liquidation) => $liquidation->where('fournisseur', 'like', '%'.$term.'%')));
                });
            })
            ->when($request->user()?->organizationScopeIds(), fn (Builder $query, array $ids) => $query->whereHas(
                'ordonnancement.liquidation.engagement.expressionBesoin',
                fn (Builder $eb) => $eb->whereIn('organization_unit_id', $ids),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        $rows = Paiement::query()->get();
        $ouvert = $rows->reject(fn (Paiement $row) => in_array($row->status, [PaiementStatus::Rejete, PaiementStatus::RejeteBancaire, PaiementStatus::Cloture], true));
        $aPayer = (int) $ouvert->sum(fn (Paiement $row) => $row->reste());
        $paye = (int) $rows->sum('montant_paye');
        $ordonnance = (int) Ordonnancement::query()->whereIn('status', ['signe', 'transmission_erreur', 'transforme_paiement'])->sum('montant');
        $parStatut = fn (PaiementStatus $status) => $rows->where('status', $status)->count();

        return [
            'total' => $rows->count(),
            'a_payer' => $aPayer,
            'paye' => $paye,
            'reste' => $aPayer,
            'payes_aujourdhui' => $rows->filter(fn (Paiement $row) => $row->date_valeur?->isToday())->count(),
            'montant_paye_aujourdhui' => (int) $rows->filter(fn (Paiement $row) => $row->date_valeur?->isToday())->sum('montant_paye'),
            'taux' => $ordonnance > 0 ? round($paye / $ordonnance * 100, 1) : 0,
            'execution' => [
                'vote' => (int) BudgetLine::query()->officielle()->sum('montant_vote'),
                'engage' => (int) Engagement::query()->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])->sum('montant'),
                'liquide' => (int) Liquidation::query()->whereNotIn('status', ['rejetee', 'annulee'])->sum('montant_net'),
                'ordonnance' => $ordonnance,
                'paye' => $paye,
            ],
            'compteurs' => [
                ['libelle' => 'À prendre en charge', 'statut' => PaiementStatus::Genere->value, 'nombre' => $parStatut(PaiementStatus::Genere)],
                ['libelle' => 'En préparation', 'statut' => PaiementStatus::EnPreparation->value, 'nombre' => $parStatut(PaiementStatus::EnPreparation)],
                ['libelle' => 'En contrôle', 'statut' => PaiementStatus::AControler->value, 'nombre' => $parStatut(PaiementStatus::AControler)],
                ['libelle' => 'À signer', 'statut' => PaiementStatus::ASigner->value, 'nombre' => $parStatut(PaiementStatus::ASigner)],
                ['libelle' => 'À exécuter', 'statut' => PaiementStatus::Autorise->value, 'nombre' => $parStatut(PaiementStatus::Autorise)],
                ['libelle' => 'Payés partiellement', 'statut' => PaiementStatus::PayePartiel->value, 'nombre' => $parStatut(PaiementStatus::PayePartiel)],
                ['libelle' => 'À rapprocher', 'statut' => PaiementStatus::ARapprocher->value, 'nombre' => $parStatut(PaiementStatus::ARapprocher)],
                ['libelle' => 'Clôturés', 'statut' => PaiementStatus::Cloture->value, 'nombre' => $parStatut(PaiementStatus::Cloture)],
                ['libelle' => 'Rejets bancaires', 'statut' => PaiementStatus::RejeteBancaire->value, 'nombre' => $parStatut(PaiementStatus::RejeteBancaire)],
                ['libelle' => 'Retournés', 'statut' => PaiementStatus::Retourne->value, 'nombre' => $parStatut(PaiementStatus::Retourne)],
            ],
            'modes' => [
                'virement' => $rows->where('mode', 'virement')->count(),
                'cheque' => $rows->where('mode', 'cheque')->count(),
                'caisse' => $rows->where('mode', 'caisse')->count(),
            ],
        ];
    }
}
