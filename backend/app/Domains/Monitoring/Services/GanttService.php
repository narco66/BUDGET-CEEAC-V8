<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GanttService
{
    /**
     * @param  Collection<int, PapEnrichment>  $activities
     * @return array<string, mixed>
     */
    public function timeline(Collection $activities): array
    {
        $activities->load(['tasks.predecessor', 'budgetLine.organizationUnit']);
        $dated = $activities->flatMap(fn (PapEnrichment $activity) => $activity->tasks)
            ->filter(fn (PapTask $task) => $task->starts_on && $task->ends_on);

        $origin = null;
        $horizon = null;
        foreach ($dated as $task) {
            foreach ([$task->starts_on, $task->actual_start] as $date) {
                if ($date && ($origin === null || $date->lt($origin))) {
                    $origin = CarbonImmutable::parse($date)->startOfDay();
                }
            }
            foreach ([$task->ends_on, $task->actual_end] as $date) {
                if ($date && ($horizon === null || $date->gt($horizon))) {
                    $horizon = CarbonImmutable::parse($date)->startOfDay();
                }
            }
        }

        $span = ($origin && $horizon) ? max(1, (int) $origin->diffInDays($horizon)) : 1;

        return [
            'debut' => $origin?->toDateString(),
            'fin' => $horizon?->toDateString(),
            'mois' => $this->months($origin, $horizon),
            'activites' => $activities->map(fn (PapEnrichment $activity) => [
                'id' => $activity->id,
                'activite' => $activity->activite,
                'taches' => $activity->tasks->map(fn (PapTask $task) => $this->bar($task, $origin, $span)),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bar(PapTask $task, ?CarbonImmutable $origin, int $span): array
    {
        $predecessor = $task->predecessor;
        $slip = $predecessor?->ends_on && $task->starts_on && $predecessor->ends_on->gt($task->starts_on);

        return [
            'id' => $task->id,
            'libelle' => $task->label,
            'poids' => (int) $task->weight,
            'avancement' => (float) ($task->progress_percent ?? 0),
            'debut' => $task->starts_on?->toDateString(),
            'fin' => $task->ends_on?->toDateString(),
            'debut_reel' => $task->actual_start?->toDateString(),
            'fin_reelle' => $task->actual_end?->toDateString(),
            'depend_de' => $predecessor ? ['id' => $predecessor->id, 'libelle' => $predecessor->label] : null,
            'prevu' => $this->segment($origin, $span, $task->starts_on, $task->ends_on),
            'reel' => $this->segment($origin, $span, $task->actual_start, $task->actual_end),
            'retard' => $task->ends_on !== null && $task->ends_on->lt(today()) && (float) $task->progress_percent < 100,
            'glissement' => $slip ? 'Le début prévu précède la fin de '.$predecessor->label.'.' : null,
        ];
    }

    /**
     * @return array{gauche: float, largeur: float}|null
     */
    private function segment(?CarbonImmutable $origin, int $span, mixed $start, mixed $end): ?array
    {
        if ($origin === null || $start === null || $end === null) {
            return null;
        }
        $left = max(0, (int) $origin->diffInDays(CarbonImmutable::parse($start)->startOfDay()));
        $width = max(1, (int) CarbonImmutable::parse($start)->startOfDay()->diffInDays(CarbonImmutable::parse($end)->startOfDay()));

        return [
            'gauche' => round($left / $span * 100, 2),
            'largeur' => round($width / $span * 100, 2),
        ];
    }

    /**
     * @return list<string>
     */
    private function months(?CarbonImmutable $origin, ?CarbonImmutable $horizon): array
    {
        if ($origin === null || $horizon === null) {
            return [];
        }
        $cursor = $origin->startOfMonth();
        $months = [];
        while ($cursor->lte($horizon)) {
            $months[] = $cursor->format('m/Y');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    public function assertSchedule(PapTask $task, ?int $dependsOn): void
    {
        if ($dependsOn === null) {
            return;
        }
        if ($dependsOn === $task->id) {
            throw ValidationException::withMessages(['depends_on_id' => 'Une tâche ne peut pas dépendre d’elle-même.']);
        }
        $seen = [$task->id];
        $cursor = $dependsOn;
        while ($cursor !== null) {
            if (in_array($cursor, $seen, true)) {
                throw ValidationException::withMessages(['depends_on_id' => 'Cette dépendance formerait une boucle.']);
            }
            $seen[] = $cursor;
            $predecessor = PapTask::query()->find($cursor);
            if ($predecessor === null || $predecessor->pap_enrichment_id !== $task->pap_enrichment_id) {
                throw ValidationException::withMessages(['depends_on_id' => 'La tâche préalable doit appartenir à la même activité.']);
            }
            $cursor = $predecessor->depends_on_id;
        }
    }
}
