<?php

namespace App\Domains\Tasks\Services;

use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Notifications\TaskAssigned;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use Illuminate\Support\Collection;

/**
 * Relances d’échéance et escalade, une fois par palier.
 * Les seuils sont dans config/gesbudep.php (taches).
 */
class TaskReminderService
{
    public function __construct(private readonly TaskProjector $projector) {}

    /**
     * @return array{relances: int, escalades: int}
     */
    public function remind(): array
    {
        $counts = ['relances' => 0, 'escalades' => 0];

        WorkflowTask::query()
            ->where('status', '!=', 'terminee')
            ->orderBy('id')
            ->each(function (WorkflowTask $task) use (&$counts) {
                if ($this->remindDue($task)) {
                    $counts['relances']++;
                }
                if ($this->escalate($task)) {
                    $counts['escalades']++;
                }
            });

        return $counts;
    }

    private function remindDue(WorkflowTask $task): bool
    {
        if ($task->due_on === null) {
            return false;
        }

        $due = $task->due_on->copy()->startOfDay();
        $today = today();
        $window = (int) config('gesbudep.taches.relance_avant_jours', 2);
        $level = (int) $task->reminder_level;
        $message = null;
        $next = $level;

        $context = $this->context($task);
        if ($due->lt($today) && $level < 3) {
            $days = (int) $due->diffInDays($today, true);
            $message = $task->dossier_reference.' : '.TaskWording::noun($task).' en retard de '.$days.' jour(s)'.$context.'.';
            $next = 3;
        } elseif ($due->equalTo($today) && $level < 2) {
            $message = $task->dossier_reference.' nécessite votre '.TaskWording::noun($task).' aujourd’hui'.$context.'.';
            $next = 2;
        } elseif ($due->gt($today) && $due->lte($today->copy()->addDays($window)) && $level < 1) {
            $message = $task->dossier_reference.' nécessite votre '.TaskWording::noun($task).' avant le '.$due->format('d/m/Y').$context.'.';
            $next = 1;
        }

        if ($message === null) {
            return false;
        }

        $this->notify($this->projector->recipients($task), $message, '/taches/'.$task->id);
        $task->forceFill(['reminder_level' => $next])->save();

        return true;
    }

    private function escalate(WorkflowTask $task): bool
    {
        if ($task->assigned_at === null) {
            return false;
        }

        /** @var array<int, int> $steps */
        $steps = config('gesbudep.taches.escalade_jours', [2, 4, 5, 7]);
        $elapsed = (int) $task->assigned_at->copy()->startOfDay()->diffInDays(today(), true);
        $level = (int) $task->escalation_level;
        $message = null;
        $next = $level;
        $recipients = $this->projector->recipients($task);

        if ($elapsed >= (int) ($steps[3] ?? 7) && $level < 4) {
            $recipients = app(RoleHolders::class)->query('directeur_budget')->get();
            $message = 'Escalade J+7 : '.$task->dossier_reference.' attend sa '.TaskWording::noun($task).' depuis '.$elapsed.' jours'.$this->context($task).'.';
            $next = 4;
        } elseif ($elapsed >= (int) ($steps[2] ?? 5) && $level < 3) {
            $recipients = $this->supervisors($task);
            $message = 'Escalade au supérieur : '.$task->dossier_reference.' attend sa '.TaskWording::noun($task).' depuis '.$elapsed.' jours'.$this->context($task).'.';
            $next = 3;
        } elseif ($elapsed >= (int) ($steps[1] ?? 4) && $level < 2) {
            $message = 'Relance renforcée : '.$task->dossier_reference.' attend votre '.TaskWording::noun($task).$this->context($task).'.';
            $next = 2;
        } elseif ($elapsed >= (int) ($steps[0] ?? 2) && $level < 1) {
            $message = 'Relance : '.$task->dossier_reference.' attend votre '.TaskWording::noun($task).' depuis '.$elapsed.' jours'.$this->context($task).'.';
            $next = 1;
        }

        if ($message === null || $recipients->isEmpty()) {
            return false;
        }

        $this->notify($recipients, $message, '/taches/'.$task->id);
        $task->forceFill(['escalation_level' => $next])->save();

        return true;
    }

    /**
     * @return Collection<int, User>
     */
    private function supervisors(WorkflowTask $task): Collection
    {
        $audience = app(TaskAudience::class);
        $directors = $audience->forRole('directeur', $task->organization_unit_id)
            ->reject(fn (User $user) => $task->assigned_user_id !== null && (int) $user->id === (int) $task->assigned_user_id)
            ->values();
        if ($directors->isNotEmpty()) {
            return $directors;
        }

        return $audience->forRole('commissaire', $task->organization_unit_id);
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notify(Collection $recipients, string $message, string $lien): void
    {
        $recipients->each(function (User $user) use ($message, $lien) {
            $user->notify((new TaskAssigned($message, $lien))->afterCommit());
        });
    }

    /**
     * Objet et montant du dossier, sans l’échéance déjà dite par la relance.
     */
    private function context(WorkflowTask $task): string
    {
        $context = TaskWording::context($task, false);

        return $context === '' ? '' : ' — '.$context;
    }
}
