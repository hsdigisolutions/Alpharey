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
            // "Sin respuesta" (no answer), NOT "no contactado" — the latter
            // collides with the "sin contactar esta semana" triage tab.
            self::NoAnswer => 'Sin respuesta',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            // "No answer", NOT "not connected" — the latter reads the same as the
            // "not contacted this week" triage and confused which is which.
            self::NoAnswer => 'No answer',
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
