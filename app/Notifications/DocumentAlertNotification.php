<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Document alerts (REQUIREMENTS.md §12): in-app bell + email, bilingual.
 * kind: expiring | expired | monthly_due | monthly_urgent | monthly_overdue
 *       | overdue_summary
 */
class DocumentAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{kind: string, title_es: string, title_en: string, entity: string|null, company: string|null, days: int|null, document_id: string|null}  $payload
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
        $subject = $this->payload['title_es'].' / '.$this->payload['title_en'];

        $message = (new MailMessage)
            ->subject($subject.' — Verto5')
            ->greeting('Hola / Hello')
            ->line($this->payload['title_es'])
            ->line($this->payload['title_en']);

        if ($this->payload['entity'] !== null) {
            $message->line('Documento / Document: '.$this->payload['entity']);
        }

        if ($this->payload['company'] !== null) {
            $message->line('Empresa / Company: '.$this->payload['company']);
        }

        return $message->salutation('Verto5');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
