<?php

namespace App\Shared\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReinitialisationMotDePasse extends Notification
{
    public function __construct(public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = urlencode((string) $notifiable->email);
        $url = rtrim((string) config('gesbudep.frontend_url'), '/').'/mot-de-passe/'.$this->token.'?email='.$email;

        return (new MailMessage)
            ->subject('Réinitialisation du mot de passe BUDGET-CEEAC')
            ->line('Une réinitialisation a été demandée pour ce compte.')
            ->action('Choisir un nouveau mot de passe', $url)
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message. Le lien expire selon la politique de session.');
    }
}
