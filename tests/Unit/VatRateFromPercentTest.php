<?php

use App\Enums\VatRate;

/**
 * The legacy database stores a raw `vat_percent`; the new schema stores this
 * enum. The mapping between them is migration-critical: get it wrong and either
 * the books stop reconciling, or a rate the client never charged appears on a
 * historical invoice.
 */
it('maps the official rates back from a stored percentage', function (): void {
    expect(VatRate::fromPercent(21))->toBe(VatRate::General)
        ->and(VatRate::fromPercent(10))->toBe(VatRate::Reducido)
        ->and(VatRate::fromPercent(4))->toBe(VatRate::Superreducido)
        ->and(VatRate::fromPercent(0))->toBe(VatRate::Exento);
});

it('accepts the decimal strings a database actually returns', function (): void {
    // MySQL hands back decimal(5,2) as '21.00', not 21
    expect(VatRate::fromPercent('21.00'))->toBe(VatRate::General)
        ->and(VatRate::fromPercent('10.00'))->toBe(VatRate::Reducido)
        ->and(VatRate::fromPercent(21.0))->toBe(VatRate::General);
});

it('treats a blank percentage as no VAT', function (): void {
    expect(VatRate::fromPercent(null))->toBeNull()
        ->and(VatRate::fromPercent(''))->toBeNull();
});

it('refuses to round an unofficial rate into a nearby one', function (): void {
    // A legacy 17% has no representation here. Guessing "close enough to 21"
    // would silently rewrite what a client was charged — the importer flags it
    // instead and keeps the stored vat_amount.
    expect(VatRate::fromPercent(17))->toBeNull()
        ->and(VatRate::fromPercent(20))->toBeNull()
        ->and(VatRate::fromPercent(22))->toBeNull()
        ->and(VatRate::fromPercent(7))->toBeNull();
});

it('is not fooled by float noise', function (): void {
    expect(VatRate::fromPercent(20.999999))->toBe(VatRate::General)
        ->and(VatRate::fromPercent(21.0001))->toBe(VatRate::General);
});
