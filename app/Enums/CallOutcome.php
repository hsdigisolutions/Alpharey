<?php

namespace App\Enums;

/**
 * The result of a call attempt on the Call Panel — did the worker actually pick
 * up? Drives the "Connected" vs "Not connected" separation on the month view.
 */
enum CallOutcome: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';

    public function labelEs(): string
    {
        return match ($this) {
            self::Connected => 'Contactado',
            self::NoAnswer => 'No contactó',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'Not connected',
        };
    }

    /**
     * Dropdown options for the log form (value + both labels), Connected first
     * (the common case, and the form default).
     *
     * @return list<array{value: string, label_es: string, label_en: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c): array => [
            'value' => $c->value,
            'label_es' => $c->labelEs(),
            'label_en' => $c->labelEn(),
        ], self::cases());
    }
}
