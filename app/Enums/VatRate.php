<?php

namespace App\Enums;

/**
 * Official Spanish IVA rates (Agencia Tributaria) — DECISIONS.md "VAT dropdown
 * policy". Every VAT field in the system is a dropdown of these options plus
 * blank ("No aplica"), which is the default: a nullable vat_rate column stores
 * the enum value, null meaning no VAT line at all.
 */
enum VatRate: string
{
    case General = 'general';
    case Reducido = 'reducido';
    case Superreducido = 'superreducido';
    case Exento = 'exento';

    public function percent(): float
    {
        return match ($this) {
            self::General => 21.0,
            self::Reducido => 10.0,
            self::Superreducido => 4.0,
            self::Exento => 0.0,
        };
    }

    public function labelEs(): string
    {
        return match ($this) {
            self::General => 'IVA General 21%',
            self::Reducido => 'IVA Reducido 10%',
            self::Superreducido => 'IVA Superreducido 4%',
            self::Exento => 'Exento 0%',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::General => 'VAT Standard 21%',
            self::Reducido => 'VAT Reduced 10%',
            self::Superreducido => 'VAT Super-reduced 4%',
            self::Exento => 'Exempt 0%',
        };
    }

    /**
     * Dropdown options for invoice/expense/proposal forms. The leading blank
     * option ("No aplica" / "Not applicable") is the default selection.
     *
     * @return list<array{value: string|null, percent: float|null, label_es: string, label_en: string}>
     */
    public static function options(): array
    {
        $options = [[
            'value' => null,
            'percent' => null,
            'label_es' => 'No aplica',
            'label_en' => 'Not applicable',
        ]];

        foreach (self::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'percent' => $case->percent(),
                'label_es' => $case->labelEs(),
                'label_en' => $case->labelEn(),
            ];
        }

        return $options;
    }

    /**
     * VAT amount for a subtotal under this rate, rounded to cents.
     */
    public function amountFor(float $subtotal): float
    {
        return round($subtotal * $this->percent() / 100, 2);
    }

    /**
     * Map a stored percentage back to a rate — the legacy database keeps a raw
     * `vat_percent` where the new schema keeps this enum.
     *
     * Returns null for null/blank AND for any percentage that is not an official
     * Agencia Tributaria rate. A legacy row at, say, 17% has no representation
     * here: the importer keeps its vat_amount exactly as stored (migration never
     * alters financial history — DATA_MIGRATION.md §3.5) and sends the row to the
     * exceptions report for a human, rather than silently rounding it to 21%.
     */
    public static function fromPercent(int|float|string|null $percent): ?self
    {
        if ($percent === null || $percent === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if (abs($case->percent() - (float) $percent) < 0.005) {
                return $case;
            }
        }

        return null;
    }
}
