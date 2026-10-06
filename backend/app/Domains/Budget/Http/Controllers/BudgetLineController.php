<?php

namespace App\Domains\Budget\Http\Controllers;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Budget\Services\CreditMovementService;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetLineController extends Controller
{
    public function __construct(
        private readonly CreditMovementService $movements,
        private readonly BudgetBalanceService $balances,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $search = $request->string('q')->trim()->toString();
        $perPage = min(500, max(1, $request->integer('per_page', 25)));

        $paginator = BudgetLine::query()
            ->officielle()
            ->with(['organizationUnit.parent', 'enrichment.tasks', 'exercice'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%")
                        ->orWhereHas('organizationUnit', fn ($unit) => $unit->where('name', 'like', "%{$search}%")->orWhere('sigle', 'like', "%{$search}%"))
                        ->orWhereHas('enrichment', fn ($enrichment) => $enrichment->where('activite', 'like', "%{$search}%"));
                });
            })
            ->orderBy('code')
            ->paginate($perPage);
        $lines = $paginator->getCollection();
        $balances = $this->balances->forLines($lines->pluck('id'));

        $lines = $lines
            ->map(function (BudgetLine $line) use ($balances) {
                $completeness = $line->nature->value === 'pap' && $line->enrichment
                    ? $line->enrichment->completeness()
                    : null;
                $balance = $balances[$line->id];

                return [
                    'id' => $line->id,
                    'code' => $line->code,
                    'libelle' => $line->label,
                    'nature' => $line->nature->value,
                    'nature_libelle' => $line->nature->label(),
                    'structure' => $line->organizationUnit?->structureLabel(),
                    'montant_vote' => $line->montant_vote,
                    'disponible' => $balance['disponible'],
                    'gele' => $balance['gele'],
                    'credit_autorise' => $balance['revise'],
                    'soldes' => $balance,
                    'completude' => $completeness['score'] ?? null,
                    'activite' => $line->enrichment?->activite,
                ];
            })
            ->values();

        return response()->json([
            'data' => $lines,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'structures' => OrganizationUnit::query()->active()->where('kind', 'direction')->orderBy('sort_order')->orderBy('sigle')->get(['id', 'sigle', 'name']),
        ]);
    }

    public function move(Request $request, BudgetLine $budgetLine): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string'],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
            'acte' => ['required', 'string', 'max:100'],
            'destination_id' => ['nullable', 'integer'],
        ]);
        $rows = $this->movements->record(
            $budgetLine,
            $request->user(),
            $data['kind'],
            (int) $data['montant'],
            $data['motif'],
            $data['acte'],
            $data['destination_id'] ?? null,
        );

        return response()->json([
            'data' => collect($rows)->map(fn ($row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'montant' => $row->amount,
                'ligne' => $row->budget_line_id,
            ]),
            'disponible' => $budgetLine->fresh()->disponible(),
        ], 201);
    }
}
