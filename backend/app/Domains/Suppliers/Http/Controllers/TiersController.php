<?php

namespace App\Domains\Suppliers\Http\Controllers;

use App\Domains\Suppliers\Models\Tiers;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Domains\Suppliers\Services\TiersService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TiersController extends Controller
{
    public function __construct(private readonly TiersService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_if(! $request->user()->holdsAny(), 403);
        $search = $request->string('q')->trim()->toString();

        $rows = Tiers::query()
            ->withCount(['bankAccounts as comptes_valides' => fn ($query) => $query->where('status', TiersBankAccount::VALIDE)])
            ->withCount(['bankAccounts as comptes_en_attente' => fn ($query) => $query->where('status', TiersBankAccount::EN_ATTENTE)])
            ->when($request->filled('statut'), fn ($query) => $query->where('status', $request->string('statut')->toString()))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('raison_sociale', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('nif', 'like', "%{$search}%")
                ->orWhere('nom_normalise', 'like', '%'.Tiers::normalize($search).'%')))
            ->orderBy('raison_sociale')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn (Tiers $tiers) => $this->summary($tiers) + [
                'comptes_valides' => (int) $tiers->comptes_valides,
                'comptes_en_attente' => (int) $tiers->comptes_en_attente,
            ]),
            'meta' => [
                'total' => $rows->total(),
                'page' => $rows->currentPage(),
                'pages' => $rows->lastPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
            'droits' => $this->rights($request->user()),
        ]);
    }

    public function show(Request $request, Tiers $tiers): JsonResponse
    {
        abort_if(! $request->user()->holdsAny(), 403);

        return response()->json(['data' => $this->detail($tiers, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->identityRules());
        $tiers = $this->service->create($request->user(), $data);

        return response()->json(['data' => $this->detail($tiers, $request->user())], 201);
    }

    public function update(Request $request, Tiers $tiers): JsonResponse
    {
        $data = $request->validate($this->identityRules());
        $tiers = $this->service->update($request->user(), $tiers, $data);

        return response()->json(['data' => $this->detail($tiers, $request->user())]);
    }

    public function destroy(Request $request, Tiers $tiers): JsonResponse
    {
        $this->service->supprimer($request->user(), $tiers);

        return response()->json(['data' => ['id' => $tiers->id]]);
    }

    public function changeStatus(Request $request, Tiers $tiers): JsonResponse
    {
        $data = $request->validate([
            'statut' => ['required', Rule::in(Tiers::STATUSES)],
            'motif' => ['required', 'string', 'max:255'],
        ]);
        $tiers = $this->service->changeStatus($request->user(), $tiers, $data['statut'], $data['motif']);

        return response()->json(['data' => $this->detail($tiers, $request->user())]);
    }

    public function storeAccount(Request $request, Tiers $tiers): JsonResponse
    {
        $data = $request->validate([
            'banque' => ['required', 'string', 'max:255'],
            'agence' => ['nullable', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:64'],
            'titulaire' => ['required', 'string', 'max:255'],
            'devise' => ['nullable', 'string', 'size:3'],
            'justificatif' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->addAccount($request->user(), $tiers, $data);

        return response()->json(['data' => $this->detail($tiers->fresh(), $request->user())], 201);
    }

    public function validateAccount(Request $request, TiersBankAccount $compte): JsonResponse
    {
        $account = $this->service->validateAccount($request->user(), $compte);

        return response()->json(['data' => $this->detail($account->tiers, $request->user())]);
    }

    public function rejectAccount(Request $request, TiersBankAccount $compte): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $account = $this->service->rejectAccount($request->user(), $compte, $data['motif']);

        return response()->json(['data' => $this->detail($account->tiers, $request->user())]);
    }

    public function deactivateAccount(Request $request, TiersBankAccount $compte): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);
        $account = $this->service->deactivateAccount($request->user(), $compte, $data['motif']);

        return response()->json(['data' => $this->detail($account->tiers, $request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Tiers $tiers): array
    {
        return [
            'id' => $tiers->id,
            'code' => $tiers->code,
            'type' => $tiers->type,
            'raison_sociale' => $tiers->raison_sociale,
            'nif' => $tiers->nif,
            'rccm' => $tiers->rccm,
            'pays' => $tiers->pays,
            'statut' => $tiers->status,
            'motif_statut' => $tiers->status_motif,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Tiers $tiers, User $actor): array
    {
        $tiers->load(['bankAccounts.createdBy', 'bankAccounts.validatedBy']);
        $canValidate = $actor->holds(...TiersService::ACCOUNT_VALIDATORS);

        return $this->summary($tiers) + [
            'adresse' => $tiers->adresse,
            'email' => $tiers->email,
            'telephone' => $tiers->telephone,
            'comptes' => $tiers->bankAccounts->map(fn (TiersBankAccount $account) => [
                'id' => $account->id,
                'banque' => $account->banque,
                'agence' => $account->agence,
                'numero' => $account->numero,
                'numero_masque' => $account->maskedNumber(),
                'titulaire' => $account->titulaire,
                'devise' => $account->devise,
                'justificatif' => $account->justificatif,
                'statut' => $account->status,
                'motif' => $account->rejection_motif,
                'saisi_par' => $account->createdBy?->name,
                'valide_par' => $account->validatedBy?->name,
                'valide_le' => $account->validated_at?->toDateTimeString(),
                'vigilance' => $account->isUnderVigilance(),
                'actions' => [
                    'valider' => $canValidate && $account->status === TiersBankAccount::EN_ATTENTE && $account->created_by !== $actor->id,
                    'rejeter' => $canValidate && $account->status === TiersBankAccount::EN_ATTENTE,
                    'desactiver' => $actor->holds(...[...TiersService::ACCOUNT_VALIDATORS, ...TiersService::ACCOUNT_CREATORS]) && $account->status !== TiersBankAccount::DESACTIVE,
                ],
            ])->values(),
            'conformites' => DB::table('tiers_compliance_documents')->where('tiers_id', $tiers->id)->orderBy('expires_on')->get()->map(fn (object $row): array => [
                'id' => $row->id,
                'kind' => $row->kind,
                'reference' => $row->reference,
                'expires_on' => $row->expires_on,
                'expiree' => $row->expires_on !== null && (string) $row->expires_on < today()->toDateString(),
            ])->all(),
            'incidents' => DB::table('tiers_incidents')->where('tiers_id', $tiers->id)->orderByDesc('occurred_on')->get()->map(fn (object $row): array => [
                'id' => $row->id,
                'occurred_on' => $row->occurred_on,
                'nature' => $row->nature,
                'suite' => $row->suite,
            ])->all(),
            'cibles' => $actor->holds(...TiersService::STATUS_MANAGERS)
                ? Tiers::query()->where('status', 'actif')->whereKeyNot($tiers->id)->orderBy('raison_sociale')->get(['id', 'code', 'raison_sociale'])->map(fn (Tiers $cible): array => [
                    'id' => $cible->id,
                    'code' => $cible->code,
                    'raison_sociale' => $cible->raison_sociale,
                ])->all()
                : [],
            'droits' => $this->rights($actor),
        ];
    }

    public function storeCompliance(Request $request, Tiers $tiers): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:80'],
            'reference' => ['required', 'string', 'max:80'],
            'expires_on' => ['required', 'date'],
        ]);
        $this->service->ajouterConformite($request->user(), $tiers, $data);

        return response()->json(['data' => $this->detail($tiers->fresh(), $request->user())], 201);
    }

    public function storeIncident(Request $request, Tiers $tiers): JsonResponse
    {
        $data = $request->validate([
            'occurred_on' => ['required', 'date'],
            'nature' => ['required', 'string', 'max:120'],
            'suite' => ['required', 'string', 'max:1000'],
        ]);
        $this->service->ajouterIncident($request->user(), $tiers, $data);

        return response()->json(['data' => $this->detail($tiers->fresh(), $request->user())], 201);
    }

    public function merge(Request $request, Tiers $tiers): JsonResponse
    {
        $data = $request->validate(['cible_id' => ['required', 'integer', 'exists:tiers,id']]);
        $cible = Tiers::query()->findOrFail($data['cible_id']);
        $this->service->fusionner($request->user(), $tiers, $cible);

        return response()->json(['data' => $this->detail($cible->fresh(), $request->user())]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function identityRules(): array
    {
        return [
            'type' => ['required', Rule::in(Tiers::TYPES)],
            'raison_sociale' => ['required', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:64'],
            'rccm' => ['nullable', 'string', 'max:64'],
            'pays' => ['nullable', 'string', 'max:64'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array{creer: bool, modifier: bool, supprimer: bool, ajouter_compte: bool, changer_statut: bool, conformite: bool, incident: bool, fusionner: bool}
     */
    private function rights(User $actor): array
    {
        return [
            'creer' => $actor->holds(...TiersService::EDITORS),
            'modifier' => $actor->holds(...TiersService::EDITORS),
            'supprimer' => $actor->holds(...TiersService::STATUS_MANAGERS),
            'ajouter_compte' => $actor->holds(...TiersService::ACCOUNT_CREATORS),
            'changer_statut' => $actor->holds(...TiersService::STATUS_MANAGERS),
            'conformite' => $actor->holds(...TiersService::EDITORS),
            'incident' => $actor->holds(...TiersService::STATUS_MANAGERS),
            'fusionner' => $actor->holds(...TiersService::STATUS_MANAGERS),
        ];
    }
}
