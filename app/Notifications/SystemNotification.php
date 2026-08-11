<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The generic bilingual DB + mail notification for the Phase-8 alert types
 * (payroll ready, invoice overdue, advance/leave pending, project alert,
 * deployment event). Mirrors DocumentAlertNotification's shape so the bell and
 * the mail layout stay consistent; the difference is only which type it
 * carries. Delivery is gated by NotificationRules before this is ever sent.
 */
class SystemNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{type: string, title_es: string, title_en: string, body_es?: string|null, body_en?: string|null, entity?: string|null, company?: string|null, url?: string|null}  $payload
     */
    public function __construct(private array $payload) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->payload['title_es'].' / '.$this->payload['title_en'].' — AlphaRey')
            ->greeting('Hola / Hello')
            ->line($this->payload['title_es'])
            ->line($this->payload['title_en']);

        if (($this->payload['body_es'] ?? null) !== null) {
            $message->line($this->payload['body_es']);
        }

        if (($this->payload['body_en'] ?? null) !== null) {
            $message->line($this->payload['body_en']);
        }

        if (($this->payload['entity'] ?? null) !== null) {
            $message->line($this->payload['entity']);
        }

        return $message->salutation('AlphaRey');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
