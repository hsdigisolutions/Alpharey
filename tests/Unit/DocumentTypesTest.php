<?php

use App\Support\DocumentTypes;

/**
 * The registry mirrors the client's own compliance workbook
 * ("DATOS OBLIGATORIOS EMPRESA.xlsx") plus the answers they gave on
 * 2026-07-16. These are business rules an audit depends on, not cosmetics —
 * so they are pinned here rather than left to a docblock.
 */
it('carries the 16 mandatory company documents from the client workbook', function (): void {
    expect(DocumentTypes::companyKeys())->toEqualCanonicalizing([
        'poliza_rc', 'recibo_rc', 'poliza_accidentes', 'recibo_accidentes',
        'certificado_spa', 'recibo_spa', 'evaluacion_riesgos', 'documento_mutua',
        'rea', 'certificado_ss', 'certificado_hacienda', 'ita', 'rnt', 'rlc',
        'recibo_rlc', 'justificante_salarios',
    ]);
});

it('never reintroduces the pre-2015 TC1/TC2 pair that RLC/RNT replaced', function (): void {
    expect(DocumentTypes::companyKeys())
        ->not->toContain('tc1_tc2')
        ->toContain('rlc')
        ->toContain('rnt');
});

it('puts the whole monthly cohort on the month-end track (client-confirmed)', function (): void {
    // Certificado SS + Hacienda are monthly per the client, NOT expiry-driven.
    expect(DocumentTypes::monthlyCompanyKeys())->toEqualCanonicalizing([
        'certificado_ss', 'certificado_hacienda',
        'ita', 'rnt', 'rlc', 'recibo_rlc', 'justificante_salarios',
    ]);
});

it('tracks no expiry date on monthly documents', function (): void {
    // DocumentStatus short-circuits to the monthly rule for these, so an
    // expiry date would be collected and then silently ignored.
    foreach (DocumentTypes::monthlyCompanyKeys() as $key) {
        expect(DocumentTypes::company()[$key]['expiry'])->toBeFalse("{$key} must not track an expiry");
    }
});

it('renews REA and the risk assessment on an expiry-driven periodic cycle', function (): void {
    expect(DocumentTypes::company()['rea'])->toBe(['frequency' => 'periodic', 'expiry' => true])
        ->and(DocumentTypes::company()['evaluacion_riesgos'])->toBe(['frequency' => 'periodic', 'expiry' => true]);
});

it('offers DNI and NIE as separate slots for Spanish and foreign workers', function (): void {
    $personal = DocumentTypes::employee()['personal'];

    expect($personal)->toHaveKeys(['dni', 'nie_fotocopia'])
        ->and($personal['dni']['expiry'])->toBeTrue()
        ->and($personal['nie_fotocopia']['expiry'])->toBeTrue();
});

it('takes the signed contract as an uploaded file, not just a date', function (): void {
    expect(DocumentTypes::employee()['employment']['contrato_trabajo'])
        ->toBe(['flag' => false, 'file' => true, 'expiry' => true]);
});

it('gives every document type a label in both languages', function (): void {
    $keys = array_merge(
        DocumentTypes::companyKeys(),
        DocumentTypes::employeeKeys(),
        DocumentTypes::projectKeys(),
    );

    // Read the dictionaries straight off disk — this suite runs without the
    // framework booted, and the check is about the files, not the translator.
    foreach (['es', 'en'] as $locale) {
        $labels = (require __DIR__."/../../lang/{$locale}/ui.php")['doc_types'];

        // Assert on the whole missing-set so a failure names every gap at once.
        expect(array_values(array_diff($keys, array_keys($labels))))->toBe([]);
    }
});

it('registers the new Spanish employee documents Baja SS and 20h PRL', function (): void {
    $employment = DocumentTypes::employee()['employment'];
    $prevencion = DocumentTypes::employee()['prevencion'];

    // Baja SS — employment category, an event record (never expires).
    expect($employment)->toHaveKey('documento_baja_ss')
        ->and($employment['documento_baja_ss'])->toBe(['flag' => false, 'file' => true, 'expiry' => false]);

    // 20h construction PRL / TPC — prevención, expiry tracked for the renewal alerts.
    expect($prevencion)->toHaveKey('formacion_prl_20h')
        ->and($prevencion['formacion_prl_20h'])->toBe(['flag' => false, 'file' => true, 'expiry' => true]);

    // The expiry-tracked one exposes an expiry_date field via the generic date fields.
    $fields = collect(DocumentTypes::fieldsFor('employee', 'formacion_prl_20h'))->pluck('key')->all();
    expect($fields)->toContain('issue_date')->toContain('expiry_date');

    // Baja has no expiry field.
    $bajaFields = collect(DocumentTypes::fieldsFor('employee', 'documento_baja_ss'))->pluck('key')->all();
    expect($bajaFields)->toContain('issue_date')->not->toContain('expiry_date');
});
