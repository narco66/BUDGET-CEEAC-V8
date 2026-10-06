<?php

namespace App\Shared\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Courriel quotidien : tâches urgentes ou en retard et avis non lus.
 * Il ne remplace pas la cloche : il prévient celui qui ne se connecte pas.
 */
class RecapitulatifQuotidien extends Notification
{
    use Queueable;

    /**
     * @param  list<array{reference: string, action: string, contexte: string, retard: bool}>  $taches
     */
    public function __construct(
        public array $taches,
        public int $totalTaches,
        public int $nonLues,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('gesbudep.frontend_url'), '/');
        $retards = count(array_filter($this->taches, fn (array $tache) => $tache['retard']));
        $mail = (new MailMessage)
            ->subject('BUDGET-CEEAC · '.$this->totalTaches.($this->totalTaches > 1 ? ' dossiers attendent' : ' dossier attend').' votre action'.($retards > 0 ? ' dont '.$retards.' en retard' : ''))
            ->greeting('Bonjour,')
            ->line('Voici les dossiers qui attendent votre intervention dans BUDGET-CEEAC.');

        foreach ($this->taches as $tache) {
            $mail->line(($tache['retard'] ? '⚠ EN RETARD · ' : '• ').$tache['reference'].' — '.$tache['action'].($tache['contexte'] !== '' ? ' — '.$tache['contexte'] : ''));
        }
        if ($this->totalTaches > count($this->taches)) {
            $mail->line('… et '.($this->totalTaches - count($this->taches)).' autre(s) dossier(s).');
        }
        if ($this->nonLues > 0) {
            $mail->line($this->nonLues.' notification(s) non lue(s) vous attendent également.');
        }

        return $mail
            ->action('Ouvrir Mes tâches', $base.'/taches')
            ->line('Vous recevez ce récapitulatif car il est activé dans vos préférences de notification. Vous pouvez le désactiver depuis l’écran Notifications.')
            ->salutation('Commission de la CEEAC');
    }
}
