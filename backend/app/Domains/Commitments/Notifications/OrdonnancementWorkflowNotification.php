<?php

namespace App\Domains\Commitments\Notifications;

use App\Domains\Commitments\Models\Ordonnancement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrdonnancementWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ordonnancement $ordonnancement,
        public string $verb,
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
            'cible' => ['type' => 'ordonnancement', 'id' => $this->ordonnancement->id],
            'module' => 'depense',
            'ordonnancement_id' => $this->ordonnancement->id,
            'reference' => $this->ordonnancement->reference,
            'message' => $this->ordonnancement->reference.' '.$this->verb,
        ];
    }
}
