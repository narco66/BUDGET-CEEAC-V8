<?php

namespace App\Domains\Needs\Notifications;

use App\Domains\Needs\Models\ExpressionBesoin;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EbWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ExpressionBesoin $expressionBesoin,
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
            'cible' => ['type' => 'expression_besoin', 'id' => $this->expressionBesoin->id],
            'module' => 'depense',
            'expression_besoin_id' => $this->expressionBesoin->id,
            'reference' => $this->expressionBesoin->reference,
            'message' => $this->expressionBesoin->reference.' '.$this->verb,
        ];
    }
}
