<?php

namespace App\Shared\Notifications;

use App\Models\User;
use App\Shared\Audit\AuditService;
use Illuminate\Notifications\Events\NotificationSent;

class JournaliserNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }
        $destinataire = $event->notifiable instanceof User ? $event->notifiable->id : null;
        try {
            app(AuditService::class)->enregistrer(
                null,
                'notification.envoyee',
                'notification',
                isset($event->notification->id) ? (string) $event->notification->id : null,
                null,
                ['classe' => $event->notification::class, 'canal' => $event->channel, 'destinataire_id' => $destinataire],
                null,
                'succes',
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
