<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gantt global de l’exécution du PAP : toutes les activités visibles de
 * l’acteur, sur une période (exercice, semestre, trimestre ou mois).
 *
 * La barre d’une activité vient, par ordre de fiabilité, de ses dates
 * planifiées, sinon de l’étendue de ses tâches datées, sinon de la période
 * saisie dans le PAP (barre « indicative »). Une activité sans aucune de ces
 * informations est comptée comme non planifiée, jamais placée au hasard.
 */
class PortfolioGanttService
{
    private const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly ActivityGanttService $activityGantt,
    ) {}

    /**
     * @param  array{annee?: int|null, periode?: string|null, unite_id?: int|null, pilier?: string|null, etat?: string|null, recherche?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters): array
    {
        $today = CarbonImmutable::today();
        $year = (int) ($filters['annee'] ?? $today->year);
        $period = $filters['periode'] ?? 'annee';
        [$start, $end, $label] = $this->window($year, $period);
        $scale = in_array($period, ['annee', 'S1', 'S2'], true) ? 'mois' : 'semaines';
        $days = max(1, (int) $start->diffInDays($end));
        $position = fn (CarbonImmutable $date): float => round(max(0, min($days, $start->diffInDays($date, false))) / $days * 100, 3);

        $activities = $this->monitoring->visible($user)->with(['tasks', 'milestones'])->get();
        $units = $activities->map(fn (PapEnrichment $a) => $a->budgetLine?->organizationUnit)->filter()->unique('id')
            ->sortBy('name')->map(fn ($unit) => ['id' => $unit->id, 'libelle' => $unit->name])->values();
        $pillars = $activities->pluck('pilier')->filter()->unique()->sort()->values();

        if (! empty($filters['unite_id'])) {
            $activities = $activities->filter(fn (PapEnrichment $a) => $a->budgetLine?->organization_unit_id === (int) $filters['unite_id']);
        }
        if (! empty($filters['pilier'])) {
            $activities = $activities->where('pilier', $filters['pilier']);
        }
        if (! empty($filters['recherche'])) {
            $needle = Str::lower(Str::ascii($filters['recherche']));
            $activities = $activities->filter(fn (PapEnrichment $a) => Str::contains(Str::lower(Str::ascii($a->activite.' '.$a->code.' '.$a->budgetLine?->code)), $needle));
        }

        $achievements = $this->latestAchievements($activities->pluck('id'));
        $rows = $activities->map(fn (PapEnrichment $activity) => $this->row($activity, $achievements, $today, $start, $end, $position));

        $unplanned = $rows->whereNull('debut');
        $inWindow = $rows->whereNotNull('debut')->filter(fn (array $row) => $row['debut'] < $end->toDateString() && $row['fin'] >= $start->toDateString());
        $shown = empty($filters['etat']) ? $inWindow : $inWindow->where('etat', $filters['etat']);
        $shown = $shown->sortBy([['debut', 'asc'], ['fin', 'asc'], ['libelle', 'asc']])->values();

        return [
            'periode' => [
                'annee' => $year,
                'code' => $period,
                'libelle' => $label,
                'debut' => $start->toDateString(),
                'fin' => $end->subDay()->toDateString(),
                'echelle' => $scale,
            ],
            'jours' => $days,
            'aujourdhui' => $today->gte($start) && $today->lt($end) ? $position($today) : null,
            'colonnes' => $this->columns($start, $end, $scale, $position),
            'periodes' => $this->upperColumns($start, $end, $scale, $position),
            'activites' => $shown,
            'non_planifiees' => $unplanned->sortBy('libelle')->map(fn (array $row) => collect($row)->only(['id', 'code', 'libelle', 'unite', 'pilier', 'statut', 'taches_sans_date'])->all())->values(),
            'synthese' => [
                'activites' => $rows->count(),
                'dans_la_periode' => $inWindow->count(),
                'planifiees' => $inWindow->whereIn('source', ['activite', 'taches'])->count(),
                'indicatives' => $inWindow->where('source', 'pap')->count(),
                'non_planifiees' => $unplanned->count(),
                'en_retard' => $inWindow->where('etat', 'en_retard')->count(),
                'en_cours' => $inWindow->where('etat', 'en_cours')->count(),
                'terminees' => $inWindow->where('etat', 'termine')->count(),
                'a_venir' => $inWindow->where('etat', 'a_venir')->count(),
                'affichees' => $shown->count(),
                'avancement_moyen' => $inWindow->isEmpty() ? null : round((float) $inWindow->avg('avancement'), 1),
            ],
            'filtres' => [
                'unites' => $units,
                'piliers' => $pillars,
                'annees' => $this->years($rows, $today),
            ],
        ];
    }

    /**
     * Période saisie dans le PAP (« 2026 », « Janvier – décembre 2026 »,
     * « T2 2026 », « S1 2026 »…) convertie en intervalle ; null si illisible.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function parsePeriod(?string $text): ?array
    {
        if ($text === null || ! preg_match('/\b(20\d{2})\b/', $text, $match)) {
            return null;
        }
        $year = (int) $match[1];
        $plain = Str::lower(Str::ascii($text));
        $first = CarbonImmutable::create($year, 1, 1);

        if (preg_match('/\b(?:t|trimestre\s*)([1-4])\b/', $plain, $quarter)) {
            $from = $first->addMonths(((int) $quarter[1] - 1) * 3);

            return [$from, $from->addMonths(3)->subDay()];
        }
        if (preg_match('/\b(?:s|semestre\s*)([12])\b/', $plain, $half)) {
            $from = $first->addMonths(((int) $half[1] - 1) * 6);

            return [$from, $from->addMonths(6)->subDay()];
        }
        $months = [];
        foreach (self::MOIS as $name => $number) {
            if (preg_match('/\b'.$name.'\b/', $plain, $found, PREG_OFFSET_CAPTURE)) {
                $months[$found[0][1]] = $number;
            }
        }
        if ($months !== []) {
            ksort($months);
            $from = $first->month(reset($months));
            $to = $first->month(end($months));
            if ($to->lt($from)) {
                $to = $to->addYear();
            }

            return [$from, $to->endOfMonth()->startOfDay()];
        }

        return [$first, $first->endOfYear()->startOfDay()];
    }

    /**
     * @param  Collection<int, float>  $achievements
     * @return array<string, mixed>
     */
    private function row(PapEnrichment $activity, Collection $achievements, CarbonImmutable $today, CarbonImmutable $start, CarbonImmutable $end, callable $position): array
    {
        $tasks = $activity->tasks->keyBy('id');
        $dated = $tasks->filter(fn (PapTask $task) => $task->starts_on && $task->ends_on);
        [$from, $to, $source] = match (true) {
            $activity->date_debut !== null && $activity->date_fin !== null => [CarbonImmutable::parse($activity->date_debut), CarbonImmutable::parse($activity->date_fin), 'activite'],
            $dated->isNotEmpty() => [CarbonImmutable::parse($dated->min('starts_on')), CarbonImmutable::parse($dated->max('ends_on')), 'taches'],
            default => [...($this->parsePeriod($activity->periode) ?? [null, null]), 'pap'],
        };

        $progress = $tasks->contains(fn (PapTask $task) => $task->progress_percent !== null)
            ? $this->monitoring->physicalRate($activity)
            : (float) ($achievements[$activity->id] ?? 0);
        $projected = $dated->isEmpty() ? [] : $this->activityGantt->project($tasks);
        $projectedEnd = collect($projected)->pluck('fin')->filter()->max();
        $actualStart = $activity->actual_start ? CarbonImmutable::parse($activity->actual_start)
            : ($tasks->pluck('actual_start')->filter()->min() ? CarbonImmutable::parse($tasks->pluck('actual_start')->filter()->min()) : null);
        $actualEnd = $activity->actual_end ? CarbonImmutable::parse($activity->actual_end) : null;
        $unit = $activity->budgetLine?->organizationUnit;

        $state = null;
        $delay = 0;
        $expected = null;
        if ($from !== null) {
            $expected = $today->lt($from) ? 0.0 : ($today->gt($to) ? 100.0 : round($from->diffInDays($today) / max(1, $from->diffInDays($to)) * 100, 1));
            if ($progress >= 100 || $actualEnd !== null) {
                $state = 'termine';
            } elseif ($to->lt($today)) {
                [$state, $delay] = ['en_retard', (int) $to->diffInDays($today)];
            } elseif ($projectedEnd !== null && $projectedEnd->gt($to)) {
                [$state, $delay] = ['en_retard', (int) $to->diffInDays($projectedEnd)];
            } elseif ($source === 'pap') {
                $state = 'indicative';
            } elseif ($from->gt($today) && $actualStart === null) {
                $state = 'a_venir';
            } else {
                $state = 'en_cours';
            }
        }

        $segment = function (?CarbonImmutable $a, ?CarbonImmutable $b) use ($start, $end, $position): ?array {
            if ($a === null || $b === null || $a->gte($end) || $b->lt($start)) {
                return null;
            }
            $gauche = $position($a->max($start));
            $droite = $position($b->addDay()->min($end));

            return ['gauche' => $gauche, 'largeur' => max(0.15, round($droite - $gauche, 3)), 'coupe_debut' => $a->lt($start), 'coupe_fin' => $b->addDay()->gt($end)];
        };

        return [
            'id' => $activity->id,
            'code' => $activity->code ?? $activity->budgetLine?->code,
            'libelle' => $activity->activite ?? $activity->budgetLine?->label ?? 'Activité '.$activity->id,
            'pilier' => $activity->pilier,
            'axe' => $activity->axe,
            'statut' => $activity->status,
            'unite' => $unit ? ['id' => $unit->id, 'libelle' => $unit->name] : null,
            'responsable' => $activity->responsible?->name ?? $activity->unite_responsable,
            'source' => $from ? $source : null,
            'periode_pap' => $activity->periode,
            'debut' => $from?->toDateString(),
            'fin' => $to?->toDateString(),
            'debut_reel' => $actualStart?->toDateString(),
            'fin_reelle' => $actualEnd?->toDateString(),
            'fin_projetee' => $projectedEnd?->toDateString(),
            'avancement' => round($progress, 1),
            'attendu' => $expected,
            'etat' => $state,
            'retard_jours' => $delay,
            'barre' => $segment($from, $to),
            'barre_reelle' => $actualStart ? $segment($actualStart, $actualEnd ?? $today->min($to ?? $today)->max($actualStart)) : null,
            'barre_projetee' => $projectedEnd && $to && $projectedEnd->gt($to) ? $segment($to->addDay(), $projectedEnd) : null,
            'jalons' => $activity->milestones->map(fn ($milestone) => [
                'libelle' => $milestone->label,
                'prevu_le' => $milestone->planned_on?->toDateString(),
                'franchi' => $milestone->achieved_on !== null,
                'position' => $milestone->planned_on && CarbonImmutable::parse($milestone->planned_on)->gte($start) && CarbonImmutable::parse($milestone->planned_on)->lt($end)
                    ? $position(CarbonImmutable::parse($milestone->planned_on)) : null,
            ])->filter(fn (array $milestone) => $milestone['position'] !== null)->values(),
            'taches' => $dated->sortBy('starts_on')->map(function (PapTask $task) use ($segment, $projected, $today) {
                $a = CarbonImmutable::parse($task->starts_on);
                $b = CarbonImmutable::parse($task->ends_on);
                $real = $task->actual_start ? CarbonImmutable::parse($task->actual_start) : null;

                return [
                    'id' => $task->id,
                    'code' => $task->code,
                    'libelle' => $task->label,
                    'debut' => $a->toDateString(),
                    'fin' => $b->toDateString(),
                    'avancement' => (float) ($task->progress_percent ?? 0),
                    'retard' => ($projected[$task->id]['retard_fin'] ?? 0) > 0 || ($b->lt($today) && (float) $task->progress_percent < 100),
                    'barre' => $segment($a, $b),
                    'barre_reelle' => $real ? $segment($real, $task->actual_end ? CarbonImmutable::parse($task->actual_end) : $today->max($real)) : null,
                ];
            })->values(),
            'taches_sans_date' => $tasks->count() - $dated->count(),
        ];
    }

    /**
     * Dernier avancement physique validé des activités sans tâche suivie, en
     * une requête (même règle que MonitoringService::physicalRate).
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, float>
     */
    private function latestAchievements(Collection $ids): Collection
    {
        return PhysicalAchievement::query()
            ->whereIn('pap_enrichment_id', $ids)
            ->whereNull('pap_task_id')
            ->whereIn('status', ['valide', 'consolide'])
            ->whereNull('superseded_at')
            ->orderBy('validated_at')
            ->get(['pap_enrichment_id', 'progress_percent'])
            ->mapWithKeys(fn (PhysicalAchievement $row) => [$row->pap_enrichment_id => (float) $row->progress_percent]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function window(int $year, string $period): array
    {
        $first = CarbonImmutable::create($year, 1, 1);

        return match (true) {
            (bool) preg_match('/^T([1-4])$/', $period, $m) => [$first->addMonths(((int) $m[1] - 1) * 3), $first->addMonths((int) $m[1] * 3), 'Trimestre '.$m[1].' '.$year],
            (bool) preg_match('/^S([12])$/', $period, $m) => [$first->addMonths(((int) $m[1] - 1) * 6), $first->addMonths((int) $m[1] * 6), 'Semestre '.$m[1].' '.$year],
            (bool) preg_match('/^M(0[1-9]|1[0-2])$/', $period, $m) => [$first->month((int) $m[1]), $first->month((int) $m[1])->addMonth(), ucfirst($first->month((int) $m[1])->locale('fr')->translatedFormat('F Y'))],
            default => [$first, $first->addYear(), 'Exercice '.$year],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(CarbonImmutable $start, CarbonImmutable $end, string $scale, callable $position): array
    {
        $columns = [];
        $cursor = $scale === 'semaines' ? $start->startOfWeek() : $start;
        while ($cursor->lt($end)) {
            $next = $scale === 'semaines' ? $cursor->addWeek() : $cursor->addMonth();
            $from = $cursor->max($start);
            $columns[] = [
                'libelle' => $scale === 'semaines' ? 'S'.$cursor->isoWeek() : ucfirst($cursor->locale('fr')->translatedFormat('M')),
                'debut' => $from->toDateString(),
                'gauche' => $position($from),
                'largeur' => round($position($next->min($end)) - $position($from), 3),
            ];
            $cursor = $next;
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upperColumns(CarbonImmutable $start, CarbonImmutable $end, string $scale, callable $position): array
    {
        $columns = [];
        $cursor = $scale === 'semaines' ? $start->startOfMonth() : $start->startOfQuarter();
        while ($cursor->lt($end)) {
            $next = $scale === 'semaines' ? $cursor->addMonth() : $cursor->addMonths(3);
            $from = $cursor->max($start);
            $columns[] = [
                'libelle' => $scale === 'semaines' ? ucfirst($cursor->locale('fr')->translatedFormat('F Y')) : 'T'.$cursor->quarter.' '.$cursor->year,
                'gauche' => $position($from),
                'largeur' => round($position($next->min($end)) - $position($from), 3),
            ];
            $cursor = $next;
        }

        return $columns;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    private function years(Collection $rows, CarbonImmutable $today): array
    {
        return $rows->flatMap(fn (array $row) => array_filter([$row['debut'] ? (int) substr($row['debut'], 0, 4) : null, $row['fin'] ? (int) substr($row['fin'], 0, 4) : null]))
            ->push($today->year)->unique()->sort()->values()->all();
    }
}
