<?php

namespace App\Domains\Commitments\Notifications;

use App\Domains\Commitments\Models\Paiement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaiementWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Paiement $paiement,
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
            'cible' => ['type' => 'paiement', 'id' => $this->paiement->id],
            'module' => 'depense',
            'paiement_id' => $this->paiement->id,
            'reference' => $this->paiement->reference,
            'message' => $this->paiement->reference.' '.$this->verb,
        ];
    }
}
