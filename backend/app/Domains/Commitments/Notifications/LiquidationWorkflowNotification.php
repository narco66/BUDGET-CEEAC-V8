<?php

namespace App\Domains\Commitments\Notifications;

use App\Domains\Commitments\Models\Liquidation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LiquidationWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Liquidation $liquidation,
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
            'cible' => ['type' => 'liquidation', 'id' => $this->liquidation->id],
            'module' => 'depense',
            'liquidation_id' => $this->liquidation->id,
            'reference' => $this->liquidation->reference,
            'message' => $this->liquidation->reference.' '.$this->verb,
        ];
    }
}
