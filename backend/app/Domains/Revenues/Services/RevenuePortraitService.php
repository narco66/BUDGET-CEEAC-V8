<?php

namespace App\Domains\Revenues\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Revenues\Models\MemberState;
use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueContribution;
use App\Domains\Revenues\Models\RevenueEvent;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenuePaymentMode;
use App\Domains\Revenues\Models\RevenueReceipt;
use App\Domains\Revenues\Models\RevenueReminder;
use App\Domains\Revenues\Models\RevenueSetting;
use App\Domains\Suppliers\Models\Tiers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RevenuePortraitService
{
    public function __construct(private readonly RevenueAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function tableau(?int $exerciceId): array
    {
        $exercice = $this->exercice($exerciceId);
        $orders = RevenueOrder::query()->where('exercice_id', $exercice->id)->get();
        $constates = $orders->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse', 'solde', 'suspendu']);
        $prevu = (int) RevenueForecast::query()->where('exercice_id', $exercice->id)->where('statut', 'valide')->sum('montant');
        $appele = (int) $orders->whereNotIn('statut', ['brouillon', 'rejete', 'annule'])->sum('montant');
        $constate = (int) $constates->sum('montant');
        $encaisse = (int) $constates->sum('montant_encaisse');
        $solde = max(0, $constate - $encaisse);
        $echu = 0;
        $nonEchu = 0;
        $retard = 0;
        $aging = ['non_echue' => 0, 'j1_30' => 0, 'j31_60' => 0, 'j61_90' => 0, 'j91_180' => 0, 'j180' => 0];
        foreach ($constates as $order) {
            if (in_array($order->statut, ['solde'], true)) {
                continue;
            }
            $reste = $order->solde();
            $bucket = $this->tranche($order->echeance);
            $aging[$bucket] += $reste;
            if ($bucket === 'non_echue') {
                $nonEchu += $reste;
            } else {
                $echu += $reste;
                $retard += $reste;
            }
        }
        $contributions = RevenueContribution::query()->with('order')->where('exercice_id', $exercice->id)->get();
        $mois = (int) RevenueReceipt::query()
            ->whereBetween('recu_le', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('montant');
        $parCategorie = RevenueOrder::query()
            ->where('exercice_id', $exercice->id)
            ->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse', 'solde', 'suspendu'])
            ->with('category')
            ->get()
            ->groupBy(fn (RevenueOrder $order) => $order->category?->label ?? 'Autre')
            ->map(fn ($rows, $label) => ['label' => $label, 'montant' => (int) $rows->sum('montant_encaisse')])
            ->values();
        $mensuel = collect(range(1, 12))->map(function (int $month) use ($exercice) {
            $montant = (int) RevenueReceipt::query()
                ->whereYear('recu_le', (int) $exercice->annee)
                ->whereMonth('recu_le', $month)
                ->sum('montant');

            return ['mois' => $month, 'montant' => $montant];
        });
        $parEtat = $contributions->map(fn (RevenueContribution $row) => [
            'etat' => $row->memberState?->nom,
            'attendu' => (int) $row->montant_attendu,
            'encaisse' => (int) ($row->order?->montant_encaisse ?? 0),
        ])->values();
        $principales = $constates
            ->filter(fn (RevenueOrder $order) => $order->solde() > 0)
            ->sortByDesc(fn (RevenueOrder $order) => $order->solde())
            ->take(8)
            ->map(fn (RevenueOrder $order) => $this->ligne($order))
            ->values();
        $tauxExercices = Exercice::query()->orderBy('annee')->get()->map(function (Exercice $row) {
            $enc = (int) RevenueOrder::query()->where('exercice_id', $row->id)->sum('montant_encaisse');
            $base = (int) RevenueOrder::query()->where('exercice_id', $row->id)->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse', 'solde', 'suspendu'])->sum('montant');

            return ['annee' => $row->annee, 'taux' => $base > 0 ? round($enc * 100 / $base, 1) : 0];
        });

        return [
            'exercice' => ['id' => $exercice->id, 'annee' => $exercice->annee, 'statut' => $exercice->statut],
            'kpi' => [
                'previsions' => $prevu,
                'appele' => $appele,
                'constate' => $constate,
                'encaisse' => $encaisse,
                'solde' => $solde,
                'taux' => $constate > 0 ? round($encaisse * 100 / $constate, 1) : 0,
                'creances_echues' => $echu,
                'creances_non_echues' => $nonEchu,
                'contributions_attendues' => (int) $contributions->sum('montant_attendu'),
                'contributions_recues' => (int) $contributions->sum(fn (RevenueContribution $row) => (int) ($row->order?->montant_encaisse ?? 0)),
                'retards' => $retard,
                'encaissements_mois' => $mois,
            ],
            'prevu_vs_encaisse' => ['prevu' => $prevu, 'encaisse' => $encaisse],
            'mensuel' => $mensuel,
            'par_categorie' => $parCategorie,
            'par_etat' => $parEtat,
            'vieillissement' => collect($aging)->map(fn (int $montant, string $code) => ['code' => $code, 'libelle' => RevenueLexicon::label($code), 'montant' => $montant])->values(),
            'taux_exercices' => $tauxExercices,
            'principales' => $principales,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function previsions(array $filters): array
    {
        $terme = trim((string) ($filters['q'] ?? ''));
        $query = RevenueForecast::query()->with(['category', 'exercice', 'organizationUnit'])
            ->when($filters['exercice_id'] ?? null, fn ($inner, $id) => $inner->where('exercice_id', $id))
            ->when($filters['statut'] ?? null, fn ($inner, $statut) => $inner->where('statut', $statut))
            ->when($filters['category_id'] ?? null, fn ($inner, $id) => $inner->where('category_id', $id))
            ->when($terme !== '', function ($inner) use ($terme) {
                $like = '%'.$terme.'%';
                $inner->where(fn ($row) => $row->where('code', 'like', $like)->orWhere('label', 'like', $like)->orWhere('source_label', 'like', $like));
            })
            ->orderByDesc('id');
        $page = $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
        $ids = collect($page->items())->pluck('id');
        $realise = RevenueOrder::query()
            ->whereIn('forecast_id', $ids)
            ->whereNotIn('statut', ['annule', 'rejete'])
            ->selectRaw('forecast_id, SUM(montant_encaisse) as encaisse')
            ->groupBy('forecast_id')
            ->pluck('encaisse', 'forecast_id');

        return [
            'data' => collect($page->items())->map(function (RevenueForecast $row) use ($realise) {
                $encaisse = (int) ($realise[$row->id] ?? 0);

                return [
                    'id' => $row->id,
                    'code' => $row->code,
                    'libelle' => $row->label,
                    'description' => $row->description,
                    'categorie' => $row->category?->label,
                    'category_id' => $row->category_id,
                    'exercice_id' => $row->exercice_id,
                    'annee' => $row->exercice?->annee,
                    'montant' => (int) $row->montant,
                    'realise' => $encaisse,
                    'ecart' => (int) $row->montant - $encaisse,
                    'source' => $row->source_label,
                    'periode' => $row->periode,
                    'structure' => $row->organizationUnit?->sigle,
                    'organization_unit_id' => $row->organization_unit_id,
                    'date_prevue' => $row->date_prevue?->toDateString(),
                    'observations' => $row->observations,
                    'statut' => $row->statut,
                    'statut_libelle' => RevenueLexicon::label($row->statut),
                ];
            })->values(),
            'meta' => $this->meta($page),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fichePrevision(RevenueForecast $forecast): array
    {
        $forecast->load(['category', 'exercice', 'organizationUnit', 'author']);
        $liste = $this->previsions(['q' => $forecast->code, 'per_page' => 5]);
        $ligne = collect($liste['data'])->firstWhere('id', $forecast->id) ?? [];
        $recettes = RevenueOrder::query()->with(['category', 'exercice'])->where('forecast_id', $forecast->id)->orderByDesc('id')->get();
        $historique = RevenueEvent::query()->with('user')->orderBy('id')
            ->where(function ($query) use ($forecast) {
                $query->where('after->forecast_id', $forecast->id)->orWhere('before->forecast_id', $forecast->id);
            })
            ->get();

        return [
            'data' => $ligne + [
                'description' => $forecast->description,
                'observations' => $forecast->observations,
                'auteur' => $forecast->author?->name,
                'structure_nom' => $forecast->organizationUnit?->name,
                'recettes' => $recettes->map(fn (RevenueOrder $order) => $this->ligne($order))->values(),
                'historique' => $historique->map(fn (RevenueEvent $event) => [
                    'action' => str_replace('_', ' ', $event->action),
                    'acteur' => $event->user?->name,
                    'date' => $event->created_at?->toDateTimeString(),
                    'avant' => $event->before,
                    'apres' => $event->after,
                ])->values(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function titres(array $filters): array
    {
        $query = $this->filtreTitres(RevenueOrder::query()->with(['category', 'exercice']), $filters);
        $page = $query->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));

        return ['data' => collect($page->items())->map(fn (RevenueOrder $order) => $this->ligne($order))->values(), 'meta' => $this->meta($page)];
    }

    /**
     * @return array<string, mixed>
     */
    public function fiche(RevenueOrder $order): array
    {
        $order->load(['category', 'exercice', 'forecast', 'author', 'verifier', 'validator', 'memberState', 'organizationUnit', 'reminders.author', 'adjustments.author', 'documents.author', 'allocations.receipt.author', 'events.user']);
        $dernier = $order->events->sortByDesc('id')->first();
        $prochaine = match ($order->statut) {
            'brouillon', 'rejete' => ['Soumettre', 'Expert budget'],
            'soumis' => ['Vérifier', 'Expert budget'],
            'verifie' => ['Valider', 'Directeur du budget'],
            'valide' => ['Prendre en charge', 'Comptable'],
            'pris_en_charge', 'partiellement_encaisse' => ['Encaisser', 'Comptable'],
            'suspendu' => ['Reprendre', 'Directeur du budget'],
            default => [null, null],
        };

        return [
            'data' => $this->ligne($order) + [
                'description' => $order->description,
                'observations' => $order->observations,
                'devise' => $order->devise,
                'auteur' => $order->author?->name,
                'verificateur' => $order->verifier?->name,
                'validateur' => $order->validator?->name,
                'structure' => $order->organizationUnit?->name,
                'banniere' => [
                    'etape' => RevenueLexicon::label($order->statut),
                    'derniere_action' => $dernier ? RevenueLexicon::label($dernier->action) : null,
                    'dernier_acteur' => $dernier?->user?->name,
                    'date' => $dernier?->created_at?->toDateTimeString(),
                    'prochaine_etape' => $prochaine[0],
                    'acteur_attendu' => $prochaine[1],
                ],
                'encaissements' => $order->allocations->map(fn ($row) => [
                    'id' => $row->receipt?->id,
                    'reference' => $row->receipt?->reference,
                    'date' => $row->receipt?->recu_le?->toDateString(),
                    'montant' => (int) $row->montant,
                    'mode' => $row->receipt?->mode,
                    'agent' => $row->receipt?->author?->name,
                ])->values(),
                'relances' => $order->reminders->map(fn (RevenueReminder $row) => [
                    'id' => $row->id,
                    'kind' => $row->kind,
                    'libelle' => RevenueLexicon::label($row->kind),
                    'canal' => $row->canal,
                    'destinataire' => $row->destinataire,
                    'resultat' => $row->resultat,
                    'prochaine_action' => $row->prochaine_action?->toDateString(),
                    'auteur' => $row->author?->name,
                    'date' => $row->created_at?->toDateTimeString(),
                ])->values(),
                'regularisations' => $order->adjustments->map(fn ($row) => [
                    'id' => $row->id,
                    'kind' => $row->kind,
                    'libelle' => RevenueLexicon::label($row->kind),
                    'montant' => (int) $row->montant,
                    'motif' => $row->motif,
                    'auteur' => $row->author?->name,
                    'date' => $row->created_at?->toDateTimeString(),
                ])->values(),
                'pieces' => $order->documents->map(fn ($row) => [
                    'id' => $row->id,
                    'nom' => $row->nom,
                    'type' => $row->type_piece,
                    'sha256' => $row->sha256,
                    'auteur' => $row->author?->name,
                ])->values(),
                'historique' => $order->events->sortBy('id')->map(fn (RevenueEvent $event) => [
                    'action' => str_replace('_', ' ', $event->action),
                    'acteur' => $event->user?->name,
                    'date' => $event->created_at?->toDateTimeString(),
                    'avant' => $event->before,
                    'apres' => $event->after,
                ])->values(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function creances(array $filters): array
    {
        $filters['ouvertes'] = true;
        $payload = $this->titres($filters);
        $aging = ['non_echue' => 0, 'j1_30' => 0, 'j31_60' => 0, 'j61_90' => 0, 'j91_180' => 0, 'j180' => 0];
        RevenueOrder::query()->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse'])->get()->each(function (RevenueOrder $order) use (&$aging): void {
            $aging[$this->tranche($order->echeance)] += $order->solde();
        });
        $payload['vieillissement'] = collect($aging)->map(fn (int $montant, string $code) => [
            'code' => $code,
            'libelle' => RevenueLexicon::label($code),
            'montant' => $montant,
        ])->values();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function contributions(?int $exerciceId): array
    {
        $exercice = $this->exercice($exerciceId);
        $rows = RevenueContribution::query()->with(['memberState', 'order.reminders'])->where('exercice_id', $exercice->id)->orderBy('member_state_id')->get();

        return [
            'exercice' => ['id' => $exercice->id, 'annee' => $exercice->annee],
            'data' => $rows->map(function (RevenueContribution $row) {
                $encaisse = (int) ($row->order?->montant_encaisse ?? 0);
                $statut = $this->statutContribution($row, $encaisse);

                return [
                    'id' => $row->id,
                    'etat' => $row->memberState?->nom,
                    'etat_id' => $row->member_state_id,
                    'quote_part' => (int) $row->quote_part,
                    'montant_attendu' => (int) $row->montant_attendu,
                    'montant_appele' => $row->order ? (int) $row->order->montant : 0,
                    'montant_encaisse' => $encaisse,
                    'solde' => max(0, (int) $row->montant_attendu - $encaisse),
                    'echeance' => $row->echeance?->toDateString(),
                    'dernier_paiement' => $row->order?->allocations()->latest('id')->first()?->receipt?->recu_le?->toDateString(),
                    'relances' => $row->order?->reminders->count() ?? 0,
                    'statut' => $statut,
                    'statut_libelle' => RevenueLexicon::label($statut),
                    'order_id' => $row->order_id,
                    'reference' => $row->order?->reference,
                    'observations' => $row->observations,
                ];
            })->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function encaissements(): array
    {
        $page = RevenueReceipt::query()->with(['author', 'allocations.order'])->orderByDesc('id')->paginate(25);

        return [
            'data' => collect($page->items())->map(fn (RevenueReceipt $receipt) => [
                'id' => $receipt->id,
                'reference' => $receipt->reference,
                'date' => $receipt->recu_le?->toDateString(),
                'montant' => (int) $receipt->montant,
                'affecte' => (int) $receipt->allocations->sum('montant'),
                'mode' => $receipt->mode,
                'banque' => $receipt->banque,
                'statut' => $receipt->statut,
                'statut_libelle' => RevenueLexicon::label($receipt->statut),
                'agent' => $receipt->author?->name,
                'titres' => $receipt->allocations->map(fn ($row) => $row->order === null ? null : ['id' => $row->order->id, 'reference' => $row->order->reference])->filter()->values(),
            ])->values(),
            'meta' => $this->meta($page),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function relances(): array
    {
        $rows = RevenueReminder::query()->with(['order', 'author'])->latest('id')->limit(100)->get();

        return [
            'data' => $rows->map(fn (RevenueReminder $row) => [
                'id' => $row->id,
                'reference' => $row->order?->reference,
                'order_id' => $row->order_id,
                'debiteur' => $row->order?->debtor_label,
                'kind' => $row->kind,
                'libelle' => RevenueLexicon::label($row->kind),
                'canal' => $row->canal,
                'destinataire' => $row->destinataire,
                'resultat' => $row->resultat,
                'prochaine_action' => $row->prochaine_action?->toDateString(),
                'auteur' => $row->author?->name,
                'date' => $row->created_at?->toDateTimeString(),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function echeancier(): array
    {
        $critique = RevenueSetting::int('critique_jours', 90);
        $orders = RevenueOrder::query()->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse'])->orderBy('echeance')->get();

        return [
            'data' => $orders->map(function (RevenueOrder $order) use ($critique) {
                $jours = $order->echeance->gte(today()) ? 0 : (int) $order->echeance->diffInDays(today(), true);

                return $this->ligne($order) + [
                    'jours_retard' => $jours,
                    'critique' => $jours >= $critique,
                    'aujourdhui' => $order->echeance->isSameDay(today()),
                ];
            })->values(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportLignes(?int $exerciceId): array
    {
        return RevenueOrder::query()
            ->with(['category', 'exercice'])
            ->when($exerciceId, fn ($query) => $query->where('exercice_id', $exerciceId))
            ->orderBy('reference')
            ->get()
            ->map(fn (RevenueOrder $order) => [
                'Référence' => $order->reference,
                'Exercice' => $order->exercice?->annee,
                'Nature' => $order->category?->label,
                'Débiteur' => $order->debtor_label,
                'Prévu' => (int) $order->montant,
                'Encaissé' => (int) $order->montant_encaisse,
                'Solde' => $order->solde(),
                'Échéance' => $order->echeance?->toDateString(),
                'Statut' => RevenueLexicon::label($order->statut),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function referentiel(): array
    {
        return [
            'categories' => RevenueCategory::query()->orderBy('label')->get(['id', 'code', 'label', 'active']),
            'modes' => RevenuePaymentMode::query()->orderBy('label')->get(['id', 'code', 'label', 'active']),
            'etats' => MemberState::query()->where('active', true)->orderBy('nom')->get(['id', 'code', 'nom']),
            'exercices' => Exercice::query()->orderByDesc('annee')->get(['id', 'annee', 'statut']),
            'services' => OrganizationUnit::query()->active()->orderBy('sort_order')->orderBy('sigle')->get(['id', 'sigle', 'name', 'parent_id', 'kind']),
            'tiers' => Tiers::query()->where('status', 'actif')->orderBy('raison_sociale')->limit(80)->get(['id', 'raison_sociale']),
            'debiteurs' => collect(RevenueLexicon::DEBTORS)->map(fn (string $code) => ['value' => $code, 'label' => RevenueLexicon::label($code)])->values(),
            'relances' => collect(RevenueLexicon::REMINDERS)->map(fn (string $code) => ['value' => $code, 'label' => RevenueLexicon::label($code)])->values(),
            'seuils' => [
                'approche_jours' => RevenueSetting::int('approche_jours', 7),
                'critique_jours' => RevenueSetting::int('critique_jours', 90),
            ],
            'droits' => $this->access->droits(request()->user()),
        ];
    }

    /**
     * @param  Builder<RevenueOrder>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<RevenueOrder>
     */
    private function filtreTitres(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['exercice_id'] ?? null, fn ($inner, $id) => $inner->where('exercice_id', $id))
            ->when($filters['statut'] ?? null, fn ($inner, $statut) => $inner->where('statut', $statut))
            ->when($filters['category_id'] ?? null, fn ($inner, $id) => $inner->where('category_id', $id))
            ->when($filters['debtor_type'] ?? null, fn ($inner, $type) => $inner->where('debtor_type', $type))
            ->when($filters['member_state_id'] ?? null, fn ($inner, $id) => $inner->where('member_state_id', $id))
            ->when($filters['organization_unit_id'] ?? null, fn ($inner, $id) => $inner->where('organization_unit_id', $id))
            ->when($filters['q'] ?? null, fn ($inner, $q) => $inner->where(fn ($where) => $where->where('reference', 'like', '%'.$q.'%')->orWhere('debtor_label', 'like', '%'.$q.'%')->orWhere('motif', 'like', '%'.$q.'%')))
            ->when(($filters['echeance'] ?? null) === 'echue', fn ($inner) => $inner->whereDate('echeance', '<', today()))
            ->when(($filters['echeance'] ?? null) === 'a_venir', fn ($inner) => $inner->whereDate('echeance', '>=', today()))
            ->when($filters['ouvertes'] ?? false, fn ($inner) => $inner->whereIn('statut', ['pris_en_charge', 'partiellement_encaisse']));
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(RevenueOrder $order): array
    {
        $jours = $order->echeance === null || $order->echeance->gte(today()) ? 0 : (int) $order->echeance->diffInDays(today(), true);
        $derniere = $order->relationLoaded('reminders') ? $order->reminders->sortByDesc('id')->first() : $order->reminders()->latest('id')->first();

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'exercice_id' => $order->exercice_id,
            'annee' => $order->exercice?->annee,
            'categorie' => $order->category?->label,
            'category_id' => $order->category_id,
            'debiteur' => $order->debtor_label,
            'debtor_type' => $order->debtor_type,
            'montant' => (int) $order->montant,
            'encaisse' => (int) $order->montant_encaisse,
            'solde' => $order->solde(),
            'echeance' => $order->echeance?->toDateString(),
            'jours_retard' => $jours,
            'statut' => $order->statut,
            'statut_libelle' => RevenueLexicon::label($order->statut),
            'motif' => $order->motif,
            'forecast_id' => $order->forecast_id,
            'prevision' => $order->relationLoaded('forecast') ? $order->forecast?->code : null,
            'derniere_relance' => $derniere?->created_at?->toDateString(),
            'prochaine_action' => $derniere?->prochaine_action?->toDateString(),
        ];
    }

    private function statutContribution(RevenueContribution $row, int $encaisse): string
    {
        $order = $row->order;
        if ($order === null) {
            return 'a_appeler';
        }
        if ($order->statut === 'annule') {
            return 'annule';
        }
        if ($order->statut === 'suspendu') {
            return 'suspendu';
        }
        if ($encaisse >= (int) $row->montant_attendu && (int) $row->montant_attendu > 0) {
            return 'soldee';
        }
        $echue = $row->echeance !== null && $row->echeance->lt(today());
        if ($encaisse > 0) {
            return $echue ? 'en_retard' : 'partiellement_payee';
        }
        if ($order->recouvrable() && $echue) {
            return 'echue';
        }

        return $order->recouvrable() ? 'appelee' : 'a_appeler';
    }

    private function tranche(?Carbon $echeance): string
    {
        if ($echeance === null || $echeance->gte(today())) {
            return 'non_echue';
        }
        $jours = (int) $echeance->diffInDays(today(), true);

        return match (true) {
            $jours <= 30 => 'j1_30',
            $jours <= 60 => 'j31_60',
            $jours <= 90 => 'j61_90',
            $jours <= 180 => 'j91_180',
            default => 'j180',
        };
    }

    private function exercice(?int $id): Exercice
    {
        if ($id !== null) {
            return Exercice::query()->findOrFail($id);
        }

        return Exercice::query()->whereIn('statut', ['executoire', 'ouvert'])->orderByDesc('annee')->first()
            ?? Exercice::query()->orderByDesc('annee')->firstOrFail();
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, int>
     */
    private function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
        ];
    }
}
