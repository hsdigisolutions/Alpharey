<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The one entry point every Phase-8 notification goes through, so the Settings
 * matrix genuinely controls delivery. It resolves the recipient list from
 * NotificationRules (role rules + company scope) and sends the generic
 * SystemNotification — a sender never picks recipients by hand.
 */
class NotificationDispatcher
{
    public function __construct(private readonly NotificationRules $rules) {}

    /**
     * @param  array{title_es: string, title_en: string, entity?: string|null, company?: string|null, url?: string|null}  $payload
     */
    public function dispatch(NotificationType $type, int $companyId, array $payload): void
    {
        $recipients = $this->rules->recipients($type, $companyId);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new SystemNotification([
            'type' => $type->value,
            'title_es' => $payload['title_es'],
            'title_en' => $payload['title_en'],
            'entity' => $payload['entity'] ?? null,
            'company' => $payload['company'] ?? null,
            'url' => $payload['url'] ?? null,
        ]));
    }
}
