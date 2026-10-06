<?php

namespace App\Domains\Budget\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PreparationAlerte extends Notification
{
    use Queueable;

    public function __construct(
        public string $message,
        public string $type,
        public int $cibleId,
        public string $lien,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'lien' => $this->lien,
            'module' => 'budget',
            'cible' => ['type' => $this->type === 'dossier' ? 'dossier_budget' : $this->type, 'id' => $this->cibleId],
        ];
    }
}
