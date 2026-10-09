<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\SeMilestone;
use App\Domains\Monitoring\Models\SePlanningRevision;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Domains\Planning\Models\GarNode;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Support\TransitionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gantt d’une activité (maquette S&E, écran 3) : planning initial validé,
 * réel, projection, dépendances fin → début, jalons, impact des retards et
 * révisions de planning soumises à validation.
 */
class ActivityGanttService
{
    /**
     * @var list<string>
     */
    public const PLANNING_VALIDATORS = ['directeur', 'directeur_budget', 'controleur_financier', 'commissaire', 'secretaire_general'];

    public function __construct(
        private readonly ActivitySheetService $sheets,
        private readonly FinancialExecutionService $finances,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(PapEnrichment $activity, string $scale = 'mois', ?User $user = null): array
    {
        $activity->loadMissing(['tasks', 'milestones', 'budgetLine.exercice']);
        $tasks = $activity->tasks->sortBy('position')->keyBy('id');
        $projected = $this->project($tasks);
        $statuses = collect($this->sheets->tasks($activity))->keyBy('id');
        $droits = [
            // Les dates prévues ne changent que par une révision validée ; le réel se constate.
            'planifier' => (bool) $user?->holds(...self::PLANNING_VALIDATORS),
            'constater' => $user !== null,
            'proposer' => $user !== null,
            'valider_revision' => (bool) $user?->holds(...self::PLANNING_VALIDATORS),
        ];

        $dates = collect([$activity->date_debut, $activity->date_fin, $activity->actual_start]);
        foreach ($tasks as $task) {
            $dates->push($task->baseline_starts_on, $task->baseline_ends_on, $task->starts_on, $task->ends_on, $task->actual_start, $task->actual_end, $projected[$task->id]['fin']);
        }
        foreach ($activity->milestones as $milestone) {
            $dates->push($milestone->planned_on, $milestone->achieved_on);
        }
        $dates = $dates->filter()->map(fn ($date) => CarbonImmutable::parse($date)->startOfDay());
        if ($dates->isEmpty()) {
            return [
                'activite' => $this->header($activity),
                'echelle' => $scale,
                'origine' => null,
                'jours' => 0,
                'colonnes' => [],
                'periodes' => [],
                'taches' => [],
                'jalons' => [],
                'impacts' => [],
                'retards' => [],
                'synthese' => $this->synthese($activity, [], null, null),
                'revisions' => $this->revisions($activity),
                'droits' => $droits,
            ];
        }
        // Une marge d’une unité d’échelle de part et d’autre : aucune barre ne colle au bord.
        $origin = $this->floor($dates->min(), $scale);
        $horizon = $this->ceil($dates->max(), $scale);
        if (today()->lt($origin) || today()->gt($horizon)) {
            $origin = $this->floor(collect([$origin, CarbonImmutable::today()])->min(), $scale);
            $horizon = $this->ceil(collect([$horizon, CarbonImmutable::today()])->max(), $scale);
        }
        $span = max(1, $origin->diffInDays($horizon));
        $position = fn ($date) => $date === null ? null : round(max(0, $origin->diffInDays(CarbonImmutable::parse($date)->startOfDay())) / $span * 100, 2);
        $segment = function ($start, $end) use ($position) {
            if ($start === null || $end === null) {
                return null;
            }
            $left = $position($start);

            return ['gauche' => $left, 'largeur' => max(0.6, round($position($end) - $left, 2))];
        };

        $codes = $tasks->mapWithKeys(fn (PapTask $task) => [$task->id => $task->code ?: 'T'.$task->position]);
        $critiques = $this->criticalPath($tasks, $projected);
        $rows = $tasks->values()->map(function (PapTask $task) use ($segment, $projected, $statuses, $codes, $critiques) {
            $projection = $projected[$task->id];
            $realEnd = $task->actual_end ?? ($task->actual_start ? today() : null);
            $finished = $task->actual_end !== null || (float) $task->progress_percent >= 100;

            return [
                'id' => $task->id,
                'code' => $codes[$task->id],
                'libelle' => $task->label,
                'responsable' => $task->responsible_label,
                'depend_de' => $task->depends_on_id ? $codes[$task->depends_on_id] ?? null : null,
                'depend_de_id' => $task->depends_on_id,
                'type_dependance' => $task->depends_on_id ? 'FD' : null,
                'critique' => in_array($task->id, $critiques, true),
                // Référence posée : les dates prévues ne changent plus que par une révision validée.
                'reference_validee' => $task->baseline_starts_on !== null || $task->baseline_ends_on !== null,
                'planifiee' => $task->starts_on !== null && $task->ends_on !== null,
                'poids' => $statuses[$task->id]['poids'] ?? null,
                'statut' => $statuses[$task->id]['statut'],
                'avancement' => $task->progress_percent !== null ? (float) $task->progress_percent : null,
                'initial' => $segment($task->baseline_starts_on ?? $task->starts_on, $task->baseline_ends_on ?? $task->ends_on),
                'prevu' => $segment($task->starts_on, $task->ends_on),
                'reel' => $segment($task->actual_start, $realEnd),
                'projection' => $finished ? null : $segment($task->actual_start ? today() : $projection['debut'], $projection['fin']),
                'retard_fin' => $projection['retard_fin'],
                'retard_demarrage' => $projection['retard_demarrage'],
                'non_demarree' => $task->actual_start === null && ! $finished,
                'debut_initial' => ($task->baseline_starts_on ?? $task->starts_on)?->toDateString(),
                'fin_initiale' => ($task->baseline_ends_on ?? $task->ends_on)?->toDateString(),
                'debut_courant' => $task->starts_on?->toDateString(),
                'fin_courante' => $task->ends_on?->toDateString(),
                'debut_reel' => $task->actual_start?->toDateString(),
                'fin_reelle' => $task->actual_end?->toDateString(),
                'fin_projetee' => $projection['fin']?->toDateString(),
            ];
        })->all();

        $activityEnd = collect($projected)->pluck('fin')->filter()->max();
        $plannedEnd = $activity->date_fin ? CarbonImmutable::parse($activity->date_fin) : $tasks->pluck('ends_on')->filter()->max();
        $exerciseYear = (int) ($activity->budgetLine?->exercice?->annee ?? today()->year);

        $plannedStart = $activity->date_debut ?? $tasks->pluck('starts_on')->filter()->min();
        $projectedStart = collect($projected)->pluck('debut')->filter()->min();

        return [
            'activite' => $this->header($activity),
            'echelle' => $scale,
            'origine' => $origin->toDateString(),
            'jours' => $span,
            'colonnes' => $this->columns($origin, $horizon, $scale, $position),
            'periodes' => $this->upperColumns($origin, $horizon, $scale, $position),
            'aujourdhui' => today()->between($origin, $horizon) ? $position(today()) : null,
            'barre_activite' => [
                'prevue' => $segment($plannedStart, $plannedEnd),
                'projetee' => $segment($projectedStart ?? $plannedStart, $activityEnd ?? $plannedEnd),
                'debut' => $plannedStart ? CarbonImmutable::parse($plannedStart)->toDateString() : null,
                'fin' => $plannedEnd ? CarbonImmutable::parse($plannedEnd)->toDateString() : null,
            ],
            'taches' => $rows,
            'jalons' => $activity->milestones->sortBy('planned_on')->map(fn (SeMilestone $milestone) => [
                'id' => $milestone->id,
                'libelle' => $milestone->label,
                'date' => ($milestone->achieved_on ?? $milestone->planned_on)?->toDateString(),
                'prevu_le' => $milestone->planned_on?->toDateString(),
                'franchi_le' => $milestone->achieved_on?->toDateString(),
                'preuve' => $milestone->proof_label,
                'responsable' => $milestone->responsible_label,
                'tache' => $milestone->pap_task_id ? $codes[$milestone->pap_task_id] ?? null : null,
                'position' => $position($milestone->achieved_on ?? $milestone->planned_on),
                'statut' => $milestone->status(),
                'retard' => $milestone->achieved_on && $milestone->planned_on && $milestone->achieved_on->gt($milestone->planned_on)
                    ? (int) $milestone->planned_on->diffInDays($milestone->achieved_on)
                    : ($milestone->achieved_on === null && $milestone->planned_on?->lt(today()) ? (int) $milestone->planned_on->diffInDays(today()) : 0),
            ])->values()->all(),
            'impacts' => $this->impacts($tasks, $projected, $codes, $exerciseYear),
            'retards' => $this->delays($activity, $tasks, $projected, $codes, $activityEnd, $plannedEnd),
            'fin_projetee' => $activityEnd?->toDateString(),
            'synthese' => $this->synthese($activity, $rows, $activityEnd, $plannedEnd),
            'revisions' => $this->revisions($activity),
            'droits' => $droits,
        ];
    }

    /**
     * Chemin critique : la chaîne de dépendances qui aboutit à la fin projetée
     * la plus tardive. Tout retard sur ces tâches retarde l’activité.
     *
     * @param  Collection<int, PapTask>  $tasks
     * @param  array<int, array<string, mixed>>  $projected
     * @return list<int>
     */
    private function criticalPath($tasks, array $projected): array
    {
        $dernier = collect($projected)->filter(fn (array $row) => $row['fin'] !== null)->sortByDesc(fn (array $row) => $row['fin']->timestamp)->keys()->first();
        $chaine = [];
        while ($dernier !== null && ! in_array($dernier, $chaine, true)) {
            $chaine[] = (int) $dernier;
            $dernier = $tasks->get($dernier)?->depends_on_id;
        }

        return count($chaine) > 1 ? $chaine : [];
    }

    /**
     * Indicateurs de tête du Gantt.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function synthese(PapEnrichment $activity, array $rows, ?CarbonImmutable $activityEnd, mixed $plannedEnd): array
    {
        $milestones = $activity->milestones;
        $plannedEnd = $plannedEnd ? CarbonImmutable::parse($plannedEnd) : null;

        return [
            'avancement' => $rows === [] ? null : $this->sheets->weightedTotal($activity),
            'taches' => count($rows),
            'taches_terminees' => collect($rows)->filter(fn (array $row) => $row['fin_reelle'] !== null || (float) ($row['avancement'] ?? 0) >= 100)->count(),
            'taches_en_retard' => collect($rows)->filter(fn (array $row) => $row['retard_fin'] > 0 || ($row['non_demarree'] && $row['retard_demarrage'] > 0))->count(),
            'jalons' => $milestones->count(),
            'jalons_franchis' => $milestones->whereNotNull('achieved_on')->count(),
            'fin_prevue' => $plannedEnd?->toDateString(),
            'fin_projetee' => $activityEnd?->toDateString(),
            'glissement' => $activityEnd && $plannedEnd && $activityEnd->gt($plannedEnd) ? (int) $plannedEnd->diffInDays($activityEnd) : 0,
        ];
    }

    /**
     * Projection fin → début : une tâche non démarrée commence au plus tôt le
     * lendemain de la fin projetée de sa tâche préalable ; une tâche en cours
     * finit à la date la plus tardive entre sa fin prévue et aujourd’hui plus
     * le reste à faire au rythme prévu.
     *
     * @param  Collection<int, PapTask>  $tasks
     * @return array<int, array{debut: CarbonImmutable|null, fin: CarbonImmutable|null, retard_fin: int, retard_demarrage: int}>
     */
    public function project($tasks): array
    {
        $result = [];
        $resolve = function (PapTask $task, array $stack = []) use (&$resolve, &$result, $tasks): array {
            if (isset($result[$task->id])) {
                return $result[$task->id];
            }
            $start = $task->starts_on ? CarbonImmutable::parse($task->starts_on) : null;
            $end = $task->ends_on ? CarbonImmutable::parse($task->ends_on) : null;
            $duration = $start && $end ? max(1, $start->diffInDays($end)) : 0;
            $progress = (float) ($task->progress_percent ?? 0);

            if ($task->actual_end !== null) {
                $projection = ['debut' => CarbonImmutable::parse($task->actual_start ?? $task->actual_end), 'fin' => CarbonImmutable::parse($task->actual_end)];
            } elseif ($task->actual_start !== null) {
                $remaining = (int) ceil($duration * (1 - min(100, $progress) / 100));
                $projection = ['debut' => CarbonImmutable::parse($task->actual_start), 'fin' => collect([$end, CarbonImmutable::today()->addDays($remaining)])->filter()->max()];
            } else {
                $earliest = $start;
                $predecessor = $task->depends_on_id ? $tasks->get($task->depends_on_id) : null;
                if ($predecessor && ! in_array($predecessor->id, $stack, true)) {
                    $before = $resolve($predecessor, [...$stack, $task->id]);
                    if ($before['fin'] && (! $earliest || $before['fin']->addDay()->gt($earliest))) {
                        $earliest = $before['fin']->addDay();
                    }
                }
                if ($earliest && $earliest->lt(CarbonImmutable::today()) && $progress < 100) {
                    $earliest = CarbonImmutable::today();
                }
                $projection = ['debut' => $earliest, 'fin' => $earliest ? $earliest->addDays($duration) : $end];
            }

            return $result[$task->id] = $projection + [
                'retard_fin' => $end && $projection['fin'] && $projection['fin']->gt($end) ? (int) $end->diffInDays($projection['fin']) : 0,
                'retard_demarrage' => $start && $task->actual_start === null && $task->actual_end === null && $start->lt(CarbonImmutable::today()) && $progress < 100
                    ? (int) $start->diffInDays(CarbonImmutable::today())
                    : ($start && $task->actual_start ? max(0, (int) $start->diffInDays(CarbonImmutable::parse($task->actual_start), false)) : 0),
            ];
        };
        foreach ($tasks as $task) {
            $resolve($task);
        }

        return $result;
    }

    /**
     * @param  Collection<int, PapTask>  $tasks
     * @param  array<int, array<string, mixed>>  $projected
     * @param  Collection<int, string>  $codes
     * @return list<string>
     */
    private function impacts($tasks, array $projected, $codes, int $year): array
    {
        $messages = [];
        foreach ($tasks as $task) {
            if (! $task->depends_on_id || $projected[$task->id]['retard_fin'] <= 0) {
                continue;
            }
            $predecessor = $tasks->get($task->depends_on_id);
            if ($predecessor === null || ($projected[$predecessor->id]['retard_fin'] <= 0 && $projected[$predecessor->id]['retard_demarrage'] <= 0)) {
                continue;
            }
            $end = $projected[$task->id]['fin'];
            $messages[] = sprintf(
                '%s %s. Comme %s dépend de %s, sa fin glisse au %s%s.',
                $codes[$predecessor->id],
                $predecessor->actual_start === null ? 'n’a pas démarré au '.today()->format('d/m/Y') : 'finira en retard',
                $codes[$task->id],
                $codes[$predecessor->id],
                $end->format('d/m/Y'),
                $end->year > $year ? ', au-delà de l’exercice' : '',
            );
        }

        return $messages;
    }

    /**
     * @param  Collection<int, PapTask>  $tasks
     * @param  array<int, array<string, mixed>>  $projected
     * @param  Collection<int, string>  $codes
     * @return list<array{libelle: string, jours: int}>
     */
    private function delays(PapEnrichment $activity, $tasks, array $projected, $codes, ?CarbonImmutable $activityEnd, mixed $plannedEnd): array
    {
        $rows = [];
        if ($activity->date_debut && $activity->actual_start && $activity->actual_start->gt($activity->date_debut)) {
            $rows[] = ['libelle' => 'Démarrage de l’activité', 'jours' => (int) $activity->date_debut->diffInDays($activity->actual_start)];
        }
        foreach ($tasks as $task) {
            $projection = $projected[$task->id];
            if ($task->actual_start === null && $projection['retard_demarrage'] > 0 && (float) $task->progress_percent < 100) {
                $rows[] = ['libelle' => $codes[$task->id].' · non démarrée', 'jours' => $projection['retard_demarrage']];
            } elseif ($projection['retard_fin'] > 0) {
                $rows[] = ['libelle' => $codes[$task->id].' · fin projetée', 'jours' => $projection['retard_fin']];
            }
        }
        if ($activityEnd && $plannedEnd && $activityEnd->gt(CarbonImmutable::parse($plannedEnd))) {
            $rows[] = ['libelle' => 'Fin d’activité projetée', 'jours' => (int) CarbonImmutable::parse($plannedEnd)->diffInDays($activityEnd)];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(PapEnrichment $activity): array
    {
        return [
            'id' => $activity->id,
            'code' => $activity->code,
            'libelle' => $activity->activite,
            'budget' => $activity->budgetLine ? (int) $this->finances->forLine($activity->budgetLine)['budget_revise'] : null,
            'debut' => $activity->date_debut?->toDateString(),
            'fin' => $activity->date_fin?->toDateString(),
            'debut_reel' => $activity->actual_start?->toDateString(),
            'unite_responsable' => $activity->unite_responsable,
            // Les tâches naissent dans la planification GAR (source unique) : lien vers le nœud de l’activité.
            'gar_noeud_id' => GarNode::query()->where('pap_enrichment_id', $activity->id)->where('type', 'activite')->orderByDesc('id')->value('id'),
        ];
    }

    /**
     * Bandeau supérieur de l’échelle : mois au-dessus des semaines, années
     * au-dessus des mois et des trimestres.
     *
     * @return list<array<string, mixed>>
     */
    private function upperColumns(CarbonImmutable $origin, CarbonImmutable $horizon, string $scale, callable $position): array
    {
        $columns = [];
        $cursor = $scale === 'semaines' ? $origin->startOfMonth() : $origin->startOfYear();
        while ($cursor->lt($horizon)) {
            $next = $scale === 'semaines' ? $cursor->addMonth() : $cursor->addYear();
            $debut = $cursor->lt($origin) ? $origin : $cursor;
            $columns[] = [
                'libelle' => $scale === 'semaines' ? ucfirst($cursor->locale('fr')->translatedFormat('F Y')) : (string) $cursor->year,
                'gauche' => $position($debut),
                'largeur' => round($position(min($next, $horizon)) - $position($debut), 2),
            ];
            $cursor = $next;
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    public function revisions(PapEnrichment $activity): array
    {
        $rows = SePlanningRevision::query()->with(['proposedBy', 'decidedBy'])->where('pap_enrichment_id', $activity->id)->orderBy('version')->get();

        return [
            'versions' => 1 + $rows->where('status', 'validee')->count(),
            'en_attente' => $rows->firstWhere('status', 'proposee')?->id,
            'historique' => $rows->map(fn (SePlanningRevision $row) => [
                'id' => $row->id,
                'version' => $row->version,
                'statut' => $row->status,
                'motif' => $row->motif,
                'propose_par' => $row->proposedBy?->name,
                'decide_par' => $row->decidedBy?->name,
                'decide_le' => $row->decided_at?->toDateString(),
                'motif_decision' => $row->decision_motif,
                'taches' => $row->tasks,
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array{id: int, starts_on: string, ends_on: string}>  $changes
     */
    public function propose(User $user, PapEnrichment $activity, string $motif, array $changes): SePlanningRevision
    {
        $ids = $activity->tasks()->pluck('id')->all();
        foreach ($changes as $change) {
            if (! in_array((int) $change['id'], $ids, true)) {
                throw ValidationException::withMessages(['taches' => 'Une tâche proposée n’appartient pas à l’activité.']);
            }
            if ($change['ends_on'] < $change['starts_on']) {
                throw ValidationException::withMessages(['taches' => 'La fin proposée ne peut pas précéder le début.']);
            }
        }

        return TransitionLock::run($activity, function (PapEnrichment $activity) use ($user, $motif, $changes) {
            if (SePlanningRevision::query()->where('pap_enrichment_id', $activity->id)->where('status', 'proposee')->exists()) {
                throw ValidationException::withMessages(['planning' => 'Une proposition de planning est déjà en attente de validation.']);
            }
            $revision = SePlanningRevision::query()->create([
                'pap_enrichment_id' => $activity->id,
                'version' => (int) SePlanningRevision::query()->where('pap_enrichment_id', $activity->id)->max('version') + 2,
                'status' => 'proposee',
                'motif' => $motif,
                'tasks' => array_values($changes),
                'proposed_by' => $user->id,
            ]);
            FinancialAudit::record($user, 'se.planning.proposer', 'se_planning_revision', (string) $revision->id, null, ['version' => $revision->version], $motif);

            return $revision;
        });
    }

    public function decide(User $user, SePlanningRevision $revision, bool $accept, ?string $motif): SePlanningRevision
    {
        if (! $user->holds(...self::PLANNING_VALIDATORS)) {
            throw ValidationException::withMessages(['action' => 'La validation d’un planning revient à la hiérarchie.']);
        }
        if (! $accept && blank($motif)) {
            throw ValidationException::withMessages(['motif' => 'Le rejet d’un planning doit être motivé.']);
        }

        return TransitionLock::run($revision, function (SePlanningRevision $revision) use ($user, $accept, $motif) {
            if ($revision->status !== 'proposee') {
                throw ValidationException::withMessages(['action' => 'Cette proposition a déjà été traitée.']);
            }
            if ($revision->proposed_by === $user->id) {
                throw ValidationException::withMessages(['action' => 'Séparation des fonctions : l’auteur d’une proposition de planning ne la valide pas.']);
            }

            return DB::transaction(function () use ($user, $accept, $motif, $revision) {
                if ($accept) {
                    foreach ($revision->tasks as $change) {
                        PapTask::query()->whereKey($change['id'])->update(['starts_on' => $change['starts_on'], 'ends_on' => $change['ends_on']]);
                    }
                }
                $revision->forceFill([
                    'status' => $accept ? 'validee' : 'rejetee',
                    'decided_by' => $user->id,
                    'decided_at' => now(),
                    'decision_motif' => $motif,
                ])->save();
                FinancialAudit::record($user, 'se.planning.'.($accept ? 'valider' : 'rejeter'), 'se_planning_revision', (string) $revision->id, null, ['version' => $revision->version], $motif);

                return $revision->fresh();
            });
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(CarbonImmutable $origin, CarbonImmutable $horizon, string $scale, callable $position): array
    {
        $columns = [];
        $cursor = $origin;
        while ($cursor->lt($horizon)) {
            $next = match ($scale) {
                'semaines' => $cursor->addWeek(),
                'trimestres' => $cursor->addMonths(3),
                default => $cursor->addMonth(),
            };
            $columns[] = [
                'libelle' => match ($scale) {
                    'semaines' => 'S'.$cursor->isoWeek(),
                    'trimestres' => 'T'.$cursor->quarter.' '.$cursor->year,
                    default => ucfirst($cursor->locale('fr')->translatedFormat('M')),
                },
                'debut' => $cursor->toDateString(),
                'gauche' => $position($cursor),
                'largeur' => round($position(min($next, $horizon)) - $position($cursor), 2),
            ];
            $cursor = $next;
        }

        return $columns;
    }

    private function floor(CarbonImmutable $date, string $scale): CarbonImmutable
    {
        return match ($scale) {
            'semaines' => $date->startOfWeek(),
            'trimestres' => $date->startOfQuarter(),
            default => $date->startOfMonth(),
        };
    }

    private function ceil(CarbonImmutable $date, string $scale): CarbonImmutable
    {
        return match ($scale) {
            'semaines' => $date->endOfWeek()->addDay()->startOfDay(),
            'trimestres' => $date->endOfQuarter()->addDay()->startOfDay(),
            default => $date->endOfMonth()->addDay()->startOfDay(),
        };
    }
}
