<?php

namespace App\Support;

/**
 * The canonical document-type registry.
 *
 * SOURCE OF TRUTH: the client's own checklist workbook
 * "DATOS OBLIGATORIOS EMPRESA.xlsx" (received 2026-07-16) — sheet EMPRESA for
 * the company documents, sheet TRABAJADORES for the worker/prevención set.
 * This replaced the earlier list that had been inferred from REQUIREMENTS.md
 * (which wrongly carried the pre-2015 TC1/TC2 pair and missed REA, SPA and the
 * payment receipts). Do not "tidy" these keys — they mirror the client's real
 * compliance folder, which is what the CAE audits check.
 *
 * Labels live in lang/{es,en}/ui.php under doc_types.* — this registry defines
 * structure only (frequency, file/flag/expiry expectations).
 */
class DocumentTypes
{
    /**
     * The 16 mandatory company documents (sheet EMPRESA, rows 5–20).
     * NOMBRE EMPRESA + CIF from that sheet are company *data* fields, not
     * documents — they live on the Company model (Settings), per the
     * "company details are data, not code" convention.
     *
     * frequency drives the alert schedule (DECISIONS.md): 'monthly' types get
     * the 5/2-days-before + 1st-of-month overdue run; everything with an expiry
     * gets the 90/60/30 + expiry-day run.
     *
     * @return array<string, array{frequency: string, expiry: bool}>
     */
    public static function company(): array
    {
        return [
            // Seguros y prevención — renewed yearly, tracked by expiry
            'poliza_rc' => ['frequency' => 'annual', 'expiry' => true],
            'recibo_rc' => ['frequency' => 'annual', 'expiry' => true],
            'poliza_accidentes' => ['frequency' => 'annual', 'expiry' => true],
            'recibo_accidentes' => ['frequency' => 'annual', 'expiry' => true],
            'certificado_spa' => ['frequency' => 'annual', 'expiry' => true],
            'recibo_spa' => ['frequency' => 'annual', 'expiry' => true],

            // Periódicos — renewal cycle other than yearly; the expiry date
            // drives the 90/60/30 + expiry-day run. REA renews every 3 years
            // (client-confirmed 2026-07-16).
            'evaluacion_riesgos' => ['frequency' => 'periodic', 'expiry' => true],
            'rea' => ['frequency' => 'periodic', 'expiry' => true],

            'documento_mutua' => ['frequency' => 'on_change', 'expiry' => false],

            // Ciclo mensual — a fresh copy is expected every month, so the
            // month-end rule governs and no expiry date is tracked (the monthly
            // branch of DocumentStatus short-circuits expiry anyway).
            // Certificado SS + Hacienda are monthly per client, 2026-07-16.
            'certificado_ss' => ['frequency' => 'monthly', 'expiry' => false],
            'certificado_hacienda' => ['frequency' => 'monthly', 'expiry' => false],
            'ita' => ['frequency' => 'monthly', 'expiry' => false],
            'rnt' => ['frequency' => 'monthly', 'expiry' => false],
            'rlc' => ['frequency' => 'monthly', 'expiry' => false],
            'recibo_rlc' => ['frequency' => 'monthly', 'expiry' => false],
            'justificante_salarios' => ['frequency' => 'monthly', 'expiry' => false],
        ];
    }

    /**
     * Worker document sets (sheet TRABAJADORES).
     *
     * The plain identity/contact columns on that sheet (NOMBRE, APELLIDOS, NIE,
     * TFNO, MAIL, Nº SS, FECHA CONTRATO) are Employee *fields*, not documents.
     * REGISTRO HORARIO is produced by the attendance module (Screen 11) and
     * ENLACE CARPETA TRABAJADOR was the legacy shared-drive link that this
     * documents engine replaces — neither is modelled as an upload slot.
     *
     * Two slots go beyond the workbook, both client-confirmed 2026-07-16:
     * `dni` (the sheet only had NIE, but part of the workforce is Spanish) and
     * `contrato_trabajo` (the sheet only had the contract *date*).
     *
     * flag = the Yes/No confirmation field; file = upload slot; expiry = date.
     *
     * @return array<string, array<string, array{flag: bool, file: bool, expiry: bool}>>
     */
    public static function employee(): array
    {
        return [
            'personal' => [
                // Spanish nationals carry a DNI, foreign residents an NIE — both
                // slots exist and each worker fills the one that applies
                // (client-confirmed 2026-07-16). Both carry a caducidad.
                'dni' => ['flag' => false, 'file' => true, 'expiry' => true],
                'nie_fotocopia' => ['flag' => false, 'file' => true, 'expiry' => true],
                'foto' => ['flag' => false, 'file' => true, 'expiry' => false],
            ],
            'employment' => [
                // The signed contract PDF itself — the workbook only tracked
                // FECHA CONTRATO (an Employee field); the client wants the file
                // too. Expiry carries the end date of a temporary contract.
                'contrato_trabajo' => ['flag' => false, 'file' => true, 'expiry' => true],
                'documento_alta_ss' => ['flag' => false, 'file' => true, 'expiry' => false],
                'documento_idc' => ['flag' => false, 'file' => true, 'expiry' => false],
            ],
            'prevencion' => [
                'aptitud_medica' => ['flag' => false, 'file' => true, 'expiry' => true],
                'formacion_art19' => ['flag' => false, 'file' => true, 'expiry' => true],
                'informacion_art18' => ['flag' => false, 'file' => true, 'expiry' => false],
                'entrega_epis' => ['flag' => false, 'file' => true, 'expiry' => false],
                'autorizacion_maquinaria' => ['flag' => false, 'file' => true, 'expiry' => false],
            ],
        ];
    }

    /**
     * Project document slots (Screen 09 Tab 7). All optional file uploads
     * with expiry; the free "custom" slot covers anything else. Grouped
     * under a single "project" category to match the employee-set shape.
     *
     * @return array<string, array<string, array{flag: bool, file: bool, expiry: bool}>>
     */
    public static function project(): array
    {
        return [
            'project' => [
                'permit' => ['flag' => false, 'file' => true, 'expiry' => true],
                'health_safety_plan' => ['flag' => false, 'file' => true, 'expiry' => true],
                'site_plan' => ['flag' => false, 'file' => true, 'expiry' => false],
                'contract' => ['flag' => false, 'file' => true, 'expiry' => true],
                'insurance' => ['flag' => false, 'file' => true, 'expiry' => true],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function projectKeys(): array
    {
        return array_keys(self::project()['project']);
    }

    /**
     * @return list<string>
     */
    public static function companyKeys(): array
    {
        return array_keys(self::company());
    }

    /**
     * @return list<string>
     */
    public static function employeeKeys(): array
    {
        $keys = [];

        foreach (self::employee() as $types) {
            $keys = array_merge($keys, array_keys($types));
        }

        return $keys;
    }

    /**
     * Company types that require a fresh upload every month
     * (alert schedule per DECISIONS.md).
     *
     * @return list<string>
     */
    public static function monthlyCompanyKeys(): array
    {
        return array_keys(array_filter(
            self::company(),
            fn (array $type): bool => $type['frequency'] === 'monthly',
        ));
    }
}
