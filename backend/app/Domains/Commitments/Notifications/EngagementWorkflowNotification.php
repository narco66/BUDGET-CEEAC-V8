<?php

namespace App\Domains\Commitments\Notifications;

use App\Domains\Commitments\Models\Engagement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EngagementWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Engagement $engagement,
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
            'cible' => ['type' => 'engagement', 'id' => $this->engagement->id],
            'module' => 'depense',
            'engagement_id' => $this->engagement->id,
            'reference' => $this->engagement->reference,
            'message' => $this->engagement->reference.' '.$this->verb,
        ];
    }
}
