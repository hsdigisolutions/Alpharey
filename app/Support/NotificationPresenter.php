<?php

namespace App\Support;

use App\Enums\NotificationType;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Normalises a stored database notification into the flat shape the bell and
 * the notifications page render — regardless of which Notification class wrote
 * it (SystemNotification carries `type`; DocumentAlertNotification now does too).
 *
 * One source of truth for icon + category so the bell and the page never drift.
 */
class NotificationPresenter
{
    /**
     * @return array{id: string, type: string|null, icon: string, category: string, title: string, title_es: string, title_en: string, body: string|null, body_es: string|null, body_en: string|null, url: string|null, read: bool, created_at: string|null, created_at_iso: string|null}
     */
    public static function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $typeValue = is_string($data['type'] ?? null) ? $data['type'] : null;
        $type = $typeValue !== null ? NotificationType::tryFrom($typeValue) : null;

        $titleEs = is_string($data['title_es'] ?? null) ? $data['title_es'] : '';
        $titleEn = is_string($data['title_en'] ?? null) ? $data['title_en'] : '';
        $bodyEs = is_string($data['body_es'] ?? null) ? $data['body_es'] : null;
        $bodyEn = is_string($data['body_en'] ?? null) ? $data['body_en'] : null;

        // Render in the VIEWER's language (Fix 7): the app locale follows the
        // signed-in user's saved preference, never the app default. English
        // falls back to Spanish when a string is missing.
        $isEn = app()->getLocale() === 'en';

        return [
            'id' => $notification->id,
            'type' => $typeValue,
            'icon' => $type?->icon() ?? '🔔',
            'category' => $type?->category() ?? 'other',
            // Localised single strings — used by the worker PWA (one language).
            'title' => $isEn ? ($titleEn !== '' ? $titleEn : $titleEs) : $titleEs,
            'body' => $isEn ? ($bodyEn ?? $bodyEs) : ($bodyEs ?? $bodyEn),
            // Both languages — used by the bilingual CRM bell + page.
            'title_es' => $titleEs,
            'title_en' => $titleEn,
            'body_es' => $bodyEs,
            'body_en' => $bodyEn,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->diffForHumans(),
            'created_at_iso' => $notification->created_at?->toIso8601String(),
        ];
    }
}
