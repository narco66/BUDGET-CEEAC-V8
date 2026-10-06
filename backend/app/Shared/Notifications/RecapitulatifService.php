<?php

namespace App\Shared\Notifications;

use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Services\TaskAudience;
use App\Domains\Tasks\Services\TaskWording;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Récapitulatif quotidien par courriel des tâches urgentes, en retard ou
 * retournées, pour les comptes actifs qui l’ont activé.
 */
class RecapitulatifService
{
    public const PREFERENCES = ['quotidien', 'aucun'];

    private const MAX_LIGNES = 15;

    public function __construct(private readonly TaskAudience $audience) {}

    public function envoyer(): int
    {
        if (! config('gesbudep.notifications.recapitulatif', true)) {
            return 0;
        }

        $envoyes = 0;
        User::query()
            ->where('account_status', 'actif')
            ->where('notifications_courriel', 'quotidien')
            ->whereNotNull('email')
            ->orderBy('id')
            ->each(function (User $user) use (&$envoyes) {
                if ($this->envoyerA($user)) {
                    $envoyes++;
                }
            });

        return $envoyes;
    }

    public function envoyerA(User $user): bool
    {
        $urgentes = $this->audience->openTasksFor($user)->filter(fn (WorkflowTask $task) => $this->urgente($task))->values();
        if ($urgentes->isEmpty()) {
            return false;
        }
        // Un seul récapitulatif par jour et par compte, même si la commande est relancée.
        if (! Cache::add('recapitulatif:'.$user->id.':'.today()->toDateString(), true, now()->endOfDay())) {
            return false;
        }

        $lignes = $urgentes->take(self::MAX_LIGNES)->map(fn (WorkflowTask $task) => [
            'reference' => (string) $task->dossier_reference,
            'action' => ucfirst(TaskWording::noun($task)),
            'contexte' => TaskWording::context($task),
            'retard' => $task->due_on !== null && $task->due_on->lt(today()),
        ])->all();

        $user->notify(new RecapitulatifQuotidien($lignes, $urgentes->count(), $user->unreadNotifications()->count()));

        return true;
    }

    private function urgente(WorkflowTask $task): bool
    {
        $fenetre = (int) config('gesbudep.taches.relance_avant_jours', 2);

        return $task->status === 'retournee'
            || in_array($task->priority, ['critique', 'haute'], true)
            || ($task->due_on !== null && $task->due_on->lte(today()->addDays($fenetre)));
    }
}
