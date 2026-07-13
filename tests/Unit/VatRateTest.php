<?php

use App\Enums\VatRate;

it('maps every rate to the official Agencia Tributaria percentage', function (): void {
    expect(VatRate::General->percent())->toBe(21.0)
        ->and(VatRate::Reducido->percent())->toBe(10.0)
        ->and(VatRate::Superreducido->percent())->toBe(4.0)
        ->and(VatRate::Exento->percent())->toBe(0.0);
});

it('offers blank as the first (default) dropdown option', function (): void {
    $options = VatRate::options();

    expect($options)->toHaveCount(5)
        ->and($options[0]['value'])->toBeNull()
        ->and($options[0]['label_es'])->toBe('No aplica')
        ->and($options[0]['label_en'])->toBe('Not applicable');
});

it('labels every rate bilingually', function (): void {
    expect(VatRate::General->labelEs())->toBe('IVA General 21%')
        ->and(VatRate::General->labelEn())->toBe('VAT Standard 21%')
        ->and(VatRate::Reducido->labelEs())->toBe('IVA Reducido 10%')
        ->and(VatRate::Exento->labelEn())->toBe('Exempt 0%');
});

it('calculates VAT amounts rounded to cents', function (): void {
    expect(VatRate::General->amountFor(100.00))->toBe(21.00)
        ->and(VatRate::Reducido->amountFor(1234.56))->toBe(123.46)
        ->and(VatRate::Superreducido->amountFor(0.10))->toBe(0.00)
        ->and(VatRate::Exento->amountFor(999.99))->toBe(0.00);
});
