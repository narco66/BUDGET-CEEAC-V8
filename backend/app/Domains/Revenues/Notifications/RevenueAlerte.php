<?php

namespace App\Domains\Revenues\Notifications;

use App\Domains\Revenues\Models\RevenueOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Information sur un titre de recette (échéance proche, encaissement).
 * Cible structurée : le titre s’ouvre directement et se filtre par module.
 */
class RevenueAlerte extends Notification
{
    use Queueable;

    public function __construct(
        public RevenueOrder $order,
        public string $message,
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
            'cible' => ['type' => 'titre', 'id' => $this->order->id],
            'module' => 'recettes',
            'revenue_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'message' => $this->message,
        ];
    }
}
