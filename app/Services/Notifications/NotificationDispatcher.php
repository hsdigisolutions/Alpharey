<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Collection;
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
     * @param  array{title_es: string, title_en: string, body_es?: string|null, body_en?: string|null, entity?: string|null, company?: string|null, url?: string|null}  $payload
     */
    public function dispatch(NotificationType $type, int $companyId, array $payload): void
    {
        $recipients = $this->rules->recipients($type, $companyId);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $this->build($type, $payload));
    }

    /**
     * Worker-direct delivery: a notification sent to ONE specific user (a
     * worker's approval result, an invited weekend offer, an assigned call).
     * Bypasses the role matrix — workers are never in it.
     *
     * @param  array{title_es: string, title_en: string, body_es?: string|null, body_en?: string|null, entity?: string|null, company?: string|null, url?: string|null}  $payload
     */
    public function dispatchToUser(NotificationType $type, ?User $user, array $payload): void
    {
        if ($user === null) {
            return;
        }

        $user->notify($this->build($type, $payload));
    }

    /**
     * Deliver to the users behind a set of employees (e.g. weekend-offer
     * invitees). Employees without a linked login are skipped.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array{title_es: string, title_en: string, body_es?: string|null, body_en?: string|null, entity?: string|null, company?: string|null, url?: string|null}  $payload
     */
    public function dispatchToEmployees(NotificationType $type, Collection $employees, array $payload): void
    {
        $users = $employees->map(fn (Employee $e) => $e->user)->filter();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, $this->build($type, $payload));
    }

    /**
     * @param  array{title_es: string, title_en: string, body_es?: string|null, body_en?: string|null, entity?: string|null, company?: string|null, url?: string|null}  $payload
     */
    private function build(NotificationType $type, array $payload): SystemNotification
    {
        return new SystemNotification([
            'type' => $type->value,
            'title_es' => $payload['title_es'],
            'title_en' => $payload['title_en'],
            // Optional longer message (bilingual), shown on the notifications page.
            'body_es' => $payload['body_es'] ?? null,
            'body_en' => $payload['body_en'] ?? null,
            'entity' => $payload['entity'] ?? null,
            'company' => $payload['company'] ?? null,
            'url' => $payload['url'] ?? null,
        ]);
    }
}
