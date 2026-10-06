<?php

namespace App\Domains\Ged\Notifications;

use App\Domains\Ged\Models\GedDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GedNotification extends Notification
{
    use Queueable;

    public function __construct(public GedDocument $document, public string $verb) {}

    /**
     * @return list<string>
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
            'cible' => ['type' => 'ged_document', 'id' => $this->document->id],
            'module' => 'ged',
            'reference' => $this->document->reference,
            'message' => $this->document->reference.' '.$this->verb,
            'lien' => '/ged/'.$this->document->id,
        ];
    }
}
