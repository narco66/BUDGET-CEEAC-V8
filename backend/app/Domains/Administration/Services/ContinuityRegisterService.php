<?php

namespace App\Domains\Administration\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Registres qui complètent les workflows : anomalies constatées, zone
 * d’import avant intégration, revue périodique des accès et instantané
 * d’exécution. Aucune de ces opérations ne réécrit le budget officiel.
 */
class ContinuityRegisterService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function relever(User $user): array
    {
        RevenueForecast::query()->where('montant', '<', 0)->orderBy('code')->each(function (RevenueForecast $forecast): void {
            $this->constater(
                'PREVISION-NEGATIVE-'.$forecast->code,
                'recettes',
                $forecast->code,
                'majeur',
                'La prévision officielle '.$forecast->code.' est importée avec un montant négatif de '.(int) $forecast->montant.' FCFA. Le montant voté n’est pas réécrit.',
            );
        });

        $codes = BudgetLine::query()->where('officiel', false)->orderBy('code')->pluck('code');
        if ($codes->isNotEmpty()) {
            $this->constater(
                'LIGNES-HORS-IMPORT',
                'budget',
                'lignes non officielles',
                'mineur',
                'Des lignes absentes de l’import officiel restent en base parce que leurs journaux sont append-only : '.$codes->implode(', ').'. Elles ne sont pas supprimées.',
            );
        }

        $this->controlesFinanciers();

        return DB::table('control_findings')->orderBy('code')->get()
            ->map(fn (object $row): array => $this->presenter($row, $user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function ouvrir(User $actor, string $reference, string $gravite, string $constat): array
    {
        $this->autoriser($actor);
        $cible = $this->cible($reference);
        $code = DB::transaction(function () use ($actor, $cible, $gravite, $constat): string {
            $code = $this->prochainCode();
            DB::table('control_findings')->insert([
                'code' => $code,
                'module' => $cible['module'],
                'subject' => $cible['sujet'],
                'severity' => $gravite,
                'detail' => trim($constat),
                'status' => 'ouvert',
                'origin' => 'manuel',
                'opened_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $code;
        });
        FinancialAudit::record($actor, 'controle.anomalie_ouverte', 'control_finding', $code, null, [
            'reference' => $cible['sujet'],
            'gravite' => $gravite,
        ]);

        return $this->presenter(DB::table('control_findings')->where('code', $code)->first(), $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function cloturer(User $actor, string $code, string $motif): array
    {
        $this->autoriser($actor);
        $ligne = DB::table('control_findings')->where('code', $code)->first();
        if ($ligne === null) {
            throw ValidationException::withMessages(['code' => 'Cette anomalie n’est pas au registre.']);
        }
        if ($ligne->status !== 'ouvert') {
            throw ValidationException::withMessages(['code' => 'Cette anomalie est déjà clôturée.']);
        }
        DB::table('control_findings')->where('code', $code)->update([
            'status' => 'clos',
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'motif_cloture' => trim($motif),
            'updated_at' => now(),
        ]);
        FinancialAudit::record($actor, 'controle.anomalie_cloturee', 'control_finding', $code, ['statut' => 'ouvert'], ['statut' => 'clos'], trim($motif));

        return $this->presenter(DB::table('control_findings')->where('code', $code)->first(), $actor);
    }

    /**
     * @return array{reference: string, statut: string, lignes: list<array<string, mixed>>}
     */
    public function preparerImport(User $actor, string $filename, string $csv): array
    {
        if (! $actor->holds('directeur_budget', 'administrateur_fonctionnel')) {
            throw ValidationException::withMessages(['action' => 'Seul le Directeur du Budget prépare un import.']);
        }
        $reference = 'IMP-'.now()->format('Ymd-His');
        $batchId = DB::table('import_batches')->insertGetId([
            'reference' => $reference,
            'filename' => $filename,
            'status' => 'controle',
            'author_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lineNo = 0;
        foreach (preg_split('/\r\n|\n|\r/', trim($csv)) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(strtolower(trim($line)), 'code')) {
                continue;
            }
            $lineNo++;
            [$code, $montant] = array_pad(str_getcsv($line), 2, null);
            $code = trim((string) $code);
            $lineModel = BudgetLine::query()->where('code', $code)->first();
            $verdict = 'en_attente';
            $message = 'Ligne inconnue : elle reste en zone de préparation et n’est pas créée.';
            if ($lineModel !== null && $lineModel->officiel) {
                $verdict = 'bloque';
                $message = 'Ligne officielle : le montant voté n’est pas modifié.';
            } elseif ($lineModel !== null) {
                $verdict = 'bloque';
                $message = 'Ligne déjà présente : l’import ne réécrit pas un montant existant.';
            }
            DB::table('import_rows')->insert([
                'import_batch_id' => $batchId,
                'line_no' => $lineNo,
                'payload' => json_encode(['code' => $code, 'montant' => $montant], JSON_THROW_ON_ERROR),
                'verdict' => $verdict,
                'message' => $message,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'reference' => $reference,
            'statut' => 'controle',
            'lignes' => DB::table('import_rows')->where('import_batch_id', $batchId)->orderBy('line_no')->get()->map(fn (object $row): array => $this->ligneImport($row))->all(),
        ];
    }

    /**
     * @return list<array{reference: string, fichier: string, controle_le: string, lignes: list<array{ligne: int, code: string, montant: mixed, verdict: string, message: string}>}>
     */
    public function lotsImport(User $actor): array
    {
        if (! $actor->holds('directeur_budget', 'administrateur_fonctionnel')) {
            throw ValidationException::withMessages(['action' => 'Seul le Directeur du Budget consulte la zone de préparation.']);
        }
        $batches = DB::table('import_batches')->orderByDesc('id')->limit(15)->get();
        $rows = DB::table('import_rows')
            ->whereIn('import_batch_id', $batches->pluck('id'))
            ->orderBy('line_no')
            ->get()
            ->groupBy('import_batch_id');

        return $batches->map(function (object $batch) use ($rows): array {
            $lignes = $rows->get($batch->id) ?? $rows->get((string) $batch->id) ?? collect();

            return [
                'reference' => $batch->reference,
                'fichier' => $batch->filename,
                'controle_le' => (string) $batch->created_at,
                'lignes' => $lignes->map(fn (object $row): array => $this->ligneImport($row))->values()->all(),
            ];
        })->all();
    }

    /**
     * @return array{ligne: int, code: string, montant: mixed, verdict: string, message: string}
     */
    private function ligneImport(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);

        return [
            'ligne' => (int) $row->line_no,
            'code' => is_array($payload) ? (string) ($payload['code'] ?? '') : '',
            'montant' => is_array($payload) ? ($payload['montant'] ?? null) : null,
            'verdict' => $row->verdict,
            'message' => $row->message,
        ];
    }

    public function photographierExecution(): string
    {
        $path = 'rapports/execution-'.now()->format('Y-m-d').'.json';
        Storage::disk('local')->put($path, json_encode([
            'genere_le' => now()->toDateTimeString(),
            'expressions' => DB::table('expression_besoins')->count(),
            'engagements' => DB::table('engagements')->count(),
            'liquidations' => DB::table('liquidations')->count(),
            'ordonnancements' => DB::table('ordonnancements')->count(),
            'paiements' => DB::table('paiements')->count(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        DB::table('report_runs')->insert([
            'kind' => 'execution',
            'path' => $path,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $path;
    }

    /**
     * @return list<array{id: int, genere_le: string, volumes: array{expressions: int, engagements: int, liquidations: int, ordonnancements: int, paiements: int}|null}>
     */
    public function instantanesExecution(): array
    {
        return DB::table('report_runs')
            ->where('kind', 'execution')
            ->orderByDesc('generated_at')
            ->limit(30)
            ->get()
            ->map(function (object $run): array {
                $volumes = null;
                if (Storage::disk('local')->exists($run->path)) {
                    $decoded = json_decode((string) Storage::disk('local')->get($run->path), true);
                    if (is_array($decoded)) {
                        $volumes = [
                            'expressions' => (int) ($decoded['expressions'] ?? 0),
                            'engagements' => (int) ($decoded['engagements'] ?? 0),
                            'liquidations' => (int) ($decoded['liquidations'] ?? 0),
                            'ordonnancements' => (int) ($decoded['ordonnancements'] ?? 0),
                            'paiements' => (int) ($decoded['paiements'] ?? 0),
                        ];
                    }
                }

                return [
                    'id' => (int) $run->id,
                    'genere_le' => (string) $run->generated_at,
                    'volumes' => $volumes,
                ];
            })
            ->all();
    }

    public function ouvrirRevue(User $actor): string
    {
        if (! $actor->holds('administrateur_habilitations', 'administrateur_fonctionnel')) {
            throw ValidationException::withMessages(['action' => 'Seul un administrateur ouvre la revue des accès.']);
        }
        if (DB::table('access_reviews')->where('status', 'ouverte')->exists()) {
            throw ValidationException::withMessages(['revue' => 'Une revue est déjà ouverte.']);
        }
        $reference = 'REVUE-'.now()->format('Ymd-His');
        $reviewId = DB::table('access_reviews')->insertGetId([
            'reference' => $reference,
            'starts_on' => today()->toDateString(),
            'status' => 'ouverte',
            'author_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        User::query()->whereNull('deactivated_at')->orderBy('id')->each(function (User $user) use ($reviewId): void {
            DB::table('access_review_items')->insert([
                'access_review_id' => $reviewId,
                'user_id' => $user->id,
                'decision' => 'en_attente',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $reference;
    }

    /**
     * @return array{reference: string|null, ouverte_le: string|null, peut_ouvrir: bool, peut_confirmer: bool, confirmees: int, attendues: int, lignes: list<array{id: int, nom: string, role: string, decision: string, decide_le: string|null}>}
     */
    public function revueOuverte(User $actor): array
    {
        if (! $actor->holds('administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur')) {
            throw ValidationException::withMessages(['action' => 'La revue des accès est réservée aux administrateurs et à l’auditeur.']);
        }
        $review = DB::table('access_reviews')->where('status', 'ouverte')->orderByDesc('id')->first();
        $peutOuvrir = $review === null && $actor->holds('administrateur_habilitations', 'administrateur_fonctionnel');
        if ($review === null) {
            return [
                'reference' => null,
                'ouverte_le' => null,
                'peut_ouvrir' => $peutOuvrir,
                'peut_confirmer' => false,
                'confirmees' => 0,
                'attendues' => 0,
                'lignes' => [],
            ];
        }
        $lignes = DB::table('access_review_items')
            ->join('users', 'users.id', '=', 'access_review_items.user_id')
            ->where('access_review_items.access_review_id', $review->id)
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
                'users.role',
                'access_review_items.decision',
                'access_review_items.decided_at',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'nom' => (string) $row->name,
                'role' => (string) $row->role,
                'decision' => (string) $row->decision,
                'decide_le' => $row->decided_at !== null ? (string) $row->decided_at : null,
            ])
            ->all();

        return [
            'reference' => $review->reference,
            'ouverte_le' => (string) $review->starts_on,
            'peut_ouvrir' => false,
            'peut_confirmer' => collect($lignes)->contains(fn (array $row): bool => $row['id'] === $actor->id && $row['decision'] === 'en_attente'),
            'confirmees' => collect($lignes)->where('decision', 'confirme')->count(),
            'attendues' => collect($lignes)->where('decision', 'en_attente')->count(),
            'lignes' => $lignes,
        ];
    }

    public function confirmerAcces(User $actor, string $reference): void
    {
        $reviewId = DB::table('access_reviews')->where('reference', $reference)->where('status', 'ouverte')->value('id');
        if ($reviewId === null) {
            throw ValidationException::withMessages(['revue' => 'Cette revue n’est pas ouverte.']);
        }
        $updated = DB::table('access_review_items')
            ->where('access_review_id', $reviewId)
            ->where('user_id', $actor->id)
            ->where('decision', 'en_attente')
            ->update(['decision' => 'confirme', 'decided_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['revue' => 'Aucun accès en attente pour ce compte.']);
        }
    }

    /**
     * Contrôles de cohérence de la chaîne (CDC §39) : chaque maillon reste dans
     * la limite du précédent. Les montants sont des entiers XAF ; aucun arrondi.
     * Un constat est seulement inscrit au registre, rien n’est corrigé en silence.
     */
    private function controlesFinanciers(): void
    {
        $soldes = app(BudgetBalanceService::class)->forLines(BudgetLine::query()->pluck('id'));
        $lignes = BudgetLine::query()->pluck('code', 'id');
        foreach ($soldes as $id => $solde) {
            if ($solde['disponible'] < 0) {
                $this->constater(
                    'DISPONIBLE-NEGATIF-'.$lignes[$id],
                    'budget',
                    (string) $lignes[$id],
                    'majeur',
                    'Les engagements et réservations de la ligne '.$lignes[$id].' dépassent le crédit révisé de '.(-$solde['disponible']).' FCFA.',
                );
            }
        }

        $actives = [LiquidationStatus::Rejetee->value, LiquidationStatus::Annulee->value];
        Engagement::query()
            ->whereNotIn('status', [EngagementStatus::Rejete->value, EngagementStatus::Annule->value])
            ->withSum(['liquidations as liquide' => fn ($query) => $query->whereNotIn('status', $actives)], 'montant_brut')
            ->get(['id', 'reference', 'montant', 'montant_degage'])
            ->each(function (Engagement $engagement): void {
                $plafond = (int) $engagement->montant - (int) $engagement->montant_degage;
                if ((int) $engagement->liquide > $plafond) {
                    $this->constater(
                        'LIQ-SUP-ENG-'.$engagement->reference,
                        'liquidations',
                        $engagement->reference,
                        'majeur',
                        'Les liquidations actives de '.$engagement->reference.' ('.(int) $engagement->liquide.' FCFA) dépassent l’engagement net ('.$plafond.' FCFA).',
                    );
                }
            });

        Ordonnancement::query()
            ->whereNotIn('ordonnancements.status', [OrdonnancementStatus::Rejete->value])
            ->join('liquidations', 'liquidations.id', '=', 'ordonnancements.liquidation_id')
            ->whereColumn('ordonnancements.montant', '>', 'liquidations.montant_net')
            ->get(['ordonnancements.reference', 'ordonnancements.montant', 'liquidations.montant_net'])
            ->each(fn ($ordre) => $this->constater(
                'ORD-SUP-LIQ-'.$ordre->reference,
                'ordonnancements',
                (string) $ordre->reference,
                'majeur',
                'L’ordre '.$ordre->reference.' ('.(int) $ordre->montant.' FCFA) dépasse le net liquidé ('.(int) $ordre->montant_net.' FCFA).',
            ));

        Paiement::query()
            ->withSum(['executions as execute' => fn ($query) => $query->where('status', '!=', PaiementExecution::REJETEE)], 'montant')
            ->get(['id', 'reference', 'montant', 'montant_paye'])
            ->filter(fn (Paiement $paiement) => (int) $paiement->montant_paye > (int) $paiement->montant || (int) $paiement->execute > (int) $paiement->montant)
            ->each(fn (Paiement $paiement) => $this->constater(
                'PAY-SUP-ORD-'.$paiement->reference,
                'paiements',
                $paiement->reference,
                'majeur',
                'Le payé cumulé de '.$paiement->reference.' ('.max((int) $paiement->montant_paye, (int) $paiement->execute).' FCFA) dépasse le montant ordonnancé ('.(int) $paiement->montant.' FCFA).',
            ));

        foreach (['engagements' => 'montant', 'liquidations' => 'montant_net', 'ordonnancements' => 'montant', 'paiements' => 'montant_paye'] as $table => $colonne) {
            $negatifs = DB::table($table)->where($colonne, '<', 0)->pluck('reference');
            if ($negatifs->isNotEmpty()) {
                $this->constater(
                    'MONTANT-NEGATIF-'.strtoupper($table),
                    $table,
                    $table,
                    'majeur',
                    'Montant négatif ('.$colonne.') sur : '.$negatifs->implode(', ').'.',
                );
            }
        }
    }

    private function constater(string $code, string $module, string $subject, string $severity, string $detail): void
    {
        $values = [
            'module' => $module,
            'subject' => $subject,
            'severity' => $severity,
            'detail' => $detail,
            'updated_at' => now(),
        ];
        if (DB::table('control_findings')->where('code', $code)->exists()) {
            DB::table('control_findings')->where('code', $code)->update($values);

            return;
        }
        DB::table('control_findings')->insert($values + [
            'code' => $code,
            'status' => 'ouvert',
            'origin' => 'automatique',
            'created_at' => now(),
        ]);
    }

    private function autoriser(User $actor): void
    {
        if (! $actor->holds('directeur_budget', 'controleur_financier', 'auditeur')) {
            throw ValidationException::withMessages(['action' => 'Seul le Directeur du Budget, le contrôleur financier ou l’auditeur ouvre ou clôture une anomalie.']);
        }
    }

    /**
     * @return array{module: string, sujet: string}
     */
    private function cible(string $reference): array
    {
        $terme = mb_strtolower(trim($reference));
        $pieces = [
            'expression_besoin' => ExpressionBesoin::class,
            'engagement' => Engagement::class,
            'liquidation' => Liquidation::class,
            'ordonnancement' => Ordonnancement::class,
            'paiement' => Paiement::class,
        ];
        foreach ($pieces as $module => $modele) {
            $sujet = $modele::query()->whereRaw('lower(reference) = ?', [$terme])->value('reference');
            if (is_string($sujet) && $sujet !== '') {
                return ['module' => $module, 'sujet' => $sujet];
            }
        }

        throw ValidationException::withMessages(['reference' => 'Aucune pièce de la chaîne ne porte cette référence.']);
    }

    private function prochainCode(): string
    {
        $prefixe = 'ANO-'.now()->year.'-';
        $dernier = DB::table('control_findings')->where('code', 'like', $prefixe.'%')->lockForUpdate()->orderByDesc('code')->value('code');
        $suite = 1;
        if (is_string($dernier) && preg_match('/(\d+)$/', $dernier, $trouves)) {
            $suite = ((int) $trouves[1]) + 1;
        }

        return $prefixe.str_pad((string) $suite, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function presenter(?object $row, User $user): array
    {
        return [
            'code' => $row->code,
            'module' => $row->module,
            'sujet' => $row->subject,
            'gravite' => $row->severity,
            'detail' => $row->detail,
            'statut' => $row->status,
            'origine' => $row->origin,
            'motif_cloture' => $row->motif_cloture,
            'peut_cloturer' => $row->status === 'ouvert' && $user->holds('directeur_budget', 'controleur_financier', 'auditeur'),
        ];
    }
}
