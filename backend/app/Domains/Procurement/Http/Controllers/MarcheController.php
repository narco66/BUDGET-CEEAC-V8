<?php

namespace App\Domains\Procurement\Http\Controllers;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Procurement\Models\Marche;
use App\Domains\Procurement\Services\MarcheService;
use App\Domains\Suppliers\Models\Tiers;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class MarcheController extends Controller
{
    public const LIBELLES_STATUTS = [
        'projet' => 'Projet',
        'notifie' => 'Notifié',
        'en_execution' => 'En exécution',
        'clos' => 'Clos',
        'resilie' => 'Résilié',
    ];

    public function __construct(private readonly MarcheService $marches) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $search = $request->string('q')->trim()->toString();
        $rows = Marche::query()
            ->with(['tiers', 'engagement', 'exercice'])
            ->when($request->filled('statut'), fn ($query) => $query->where('statut', $request->string('statut')->toString()))
            ->when($request->filled('procedure'), fn ($query) => $query->where('procedure', $request->string('procedure')->toString()))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('objet', 'like', "%{$search}%")
                ->orWhereHas('tiers', fn ($tiers) => $tiers->where('raison_sociale', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn (Marche $marche): array => $this->resume($marche))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'procedures' => MarcheService::PROCEDURES,
            'statuts' => self::LIBELLES_STATUTS,
            'peut_creer' => $this->marches->peutModifier($request->user()),
            'exercices' => Exercice::query()->whereIn('statut', ['ouvert', 'executoire'])->orderByDesc('annee')->get(['id', 'annee']),
            'engagements' => $this->engagementsLibres(),
            'tiers' => Tiers::query()->where('status', 'actif')->orderBy('raison_sociale')->get(['id', 'code', 'raison_sociale']),
        ]);
    }

    public function show(Request $request, Marche $marche): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);

        return response()->json(['data' => $this->detail($marche, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exercice_id' => ['required', 'integer'],
            'objet' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:1'],
            'procedure' => ['required', 'string'],
            'tiers_id' => ['nullable', 'integer'],
        ]);
        $marche = $this->marches->creer($request->user(), $data);

        return response()->json(['data' => ['id' => $marche->id, 'reference' => $marche->reference]], 201);
    }

    public function update(Request $request, Marche $marche): JsonResponse
    {
        $data = $request->validate([
            'objet' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:1'],
            'procedure' => ['required', 'string'],
            'tiers_id' => ['nullable', 'integer'],
        ]);
        $marche = $this->marches->modifier($request->user(), $marche, $data);

        return response()->json(['data' => $this->detail($marche, $request->user())]);
    }

    public function changeStatus(Request $request, Marche $marche): JsonResponse
    {
        $data = $request->validate([
            'statut' => ['required', Rule::in(MarcheService::STATUTS)],
            'motif' => ['nullable', 'string', 'max:255'],
            'notified_on' => ['nullable', 'date'],
        ]);
        $marche = $this->marches->changerStatut($request->user(), $marche, $data['statut'], $data['motif'] ?? null, $data['notified_on'] ?? null);

        return response()->json(['data' => $this->detail($marche, $request->user())]);
    }

    public function destroy(Request $request, Marche $marche): JsonResponse
    {
        $this->marches->supprimer($request->user(), $marche);

        return response()->json(['data' => ['id' => $marche->id]]);
    }

    public function attach(Request $request, Marche $marche): JsonResponse
    {
        $data = $request->validate(['engagement_id' => ['required', 'integer']]);
        $engagement = Engagement::query()->findOrFail($data['engagement_id']);
        $this->marches->rattacher($marche, $engagement, $request->user());

        return response()->json(['data' => ['id' => $marche->id, 'engagement_id' => $engagement->id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resume(Marche $marche): array
    {
        return [
            'id' => $marche->id,
            'reference' => $marche->reference,
            'objet' => $marche->objet,
            'montant' => (int) $marche->montant,
            'procedure' => $marche->procedure,
            'statut' => $marche->statut,
            'statut_libelle' => self::LIBELLES_STATUTS[$marche->statut] ?? $marche->statut,
            'titulaire' => $marche->tiers?->raison_sociale,
            'tiers_id' => $marche->tiers_id,
            'engagement' => $marche->engagement?->reference,
            'engagement_id' => $marche->engagement_id,
            'annee' => $marche->exercice?->annee,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Marche $marche, User $actor): array
    {
        $marche->loadMissing(['tiers', 'engagement', 'exercice', 'author']);
        $editeur = $this->marches->peutModifier($actor);

        return $this->resume($marche) + [
            'notifie_le' => $marche->notified_on?->toDateString(),
            'titulaire_code' => $marche->tiers?->code,
            'engagement_montant' => $marche->engagement !== null ? (int) $marche->engagement->montant : null,
            'cree_par' => $marche->author?->name,
            'cree_le' => $marche->created_at?->toDateTimeString(),
            'historique' => AuditEvent::query()
                ->where('object_type', 'marche')
                ->where('object_id', (string) $marche->id)
                ->with('actor:id,name')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'action', 'motif', 'occurred_at', 'created_at', 'actor_id', 'actor_name'])
                ->map(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'motif' => $event->motif,
                    'le' => ($event->occurred_at ?? $event->created_at)?->toDateTimeString(),
                    'acteur' => $event->actor_name ?? $event->actor?->name,
                ])
                ->all(),
            'transitions' => $editeur ? MarcheService::TRANSITIONS[$marche->statut] ?? [] : [],
            'actions' => [
                'modifier' => $editeur && $marche->statut === 'projet',
                'supprimer' => $editeur && $marche->statut === 'projet' && $marche->engagement_id === null,
                'rattacher' => $editeur && $marche->engagement_id === null && ! in_array($marche->statut, ['clos', 'resilie'], true),
            ],
            'engagements' => $editeur && $marche->engagement_id === null ? $this->engagementsLibres() : [],
            'tiers' => $editeur && $marche->statut === 'projet' ? Tiers::query()->where('status', 'actif')->orderBy('raison_sociale')->get(['id', 'code', 'raison_sociale']) : [],
        ];
    }

    /**
     * @return Collection<int, Engagement>
     */
    private function engagementsLibres(): Collection
    {
        return Engagement::query()
            ->whereNotIn('id', Marche::query()->whereNotNull('engagement_id')->select('engagement_id'))
            ->latest('id')
            ->limit(40)
            ->get(['id', 'reference']);
    }
}
