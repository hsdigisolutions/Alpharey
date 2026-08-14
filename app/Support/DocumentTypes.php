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
     * Per-type descriptive fields for the smart company-document detail panel
     * (client-confirmed spec, 2026-08-14). Each type declares an ordered field
     * list. EVERY type also carries a repeatable point-of-contact section
     * (stored in the `contacts` JSON column, not the registry).
     *
     * A field entry:
     *   key      — stable identifier (also the doc_fields.* label suffix)
     *   type     — text|number|money|date|select|tel|email|textarea|
     *              checkbox_group|month_year|ccc
     *   label    — translation key (doc_fields.*)
     *   column   — OPTIONAL: binds to a top-level Document column
     *              (issue_date | expiry_date) instead of the metadata JSON, so
     *              the existing 90/60/30 alert engine keeps working off
     *              expiry_date. Every type uses each column at most once.
     *   options  — OPTIONAL: option values for select/checkbox_group; each
     *              value's label is doc_fields.opt_{value}.
     *
     * type 'ccc' renders READ-ONLY from the company's own CCC (Q3) — it is never
     * a stored metadata key.
     *
     * @return array<string, array{fields: list<array{key: string, type: string, label: string, column?: string, options?: list<string>}>}>
     */
    public static function companyFields(): array
    {
        // Shared by recibo_rc + recibo_accidentes (both carry forma de pago).
        $receiptFields = [
            ['key' => 'receipt_number', 'type' => 'text', 'label' => 'doc_fields.receipt_number'],
            ['key' => 'amount_paid', 'type' => 'money', 'label' => 'doc_fields.amount_paid'],
            ['key' => 'payment_method', 'type' => 'select', 'label' => 'doc_fields.payment_method', 'options' => ['transferencia', 'domiciliacion', 'cheque']],
            ['key' => 'payment_date', 'type' => 'date', 'label' => 'doc_fields.payment_date', 'column' => 'issue_date'],
            ['key' => 'period_covered', 'type' => 'text', 'label' => 'doc_fields.period_covered'],
        ];

        return [
            // ── Seguros y prevención ──────────────────────────────────────────
            'poliza_rc' => [
                'fields' => [
                    ['key' => 'policy_number', 'type' => 'text', 'label' => 'doc_fields.policy_number'],
                    ['key' => 'insurer', 'type' => 'text', 'label' => 'doc_fields.insurer'],
                    ['key' => 'policy_type', 'type' => 'select', 'label' => 'doc_fields.policy_type', 'options' => ['anual_abierta', 'por_obra']],
                    ['key' => 'coverage_amount', 'type' => 'money', 'label' => 'doc_fields.coverage_amount'],
                    ['key' => 'premium', 'type' => 'money', 'label' => 'doc_fields.premium'],
                    ['key' => 'start_date', 'type' => 'date', 'label' => 'doc_fields.start_date', 'column' => 'issue_date'],
                    ['key' => 'end_date', 'type' => 'date', 'label' => 'doc_fields.end_date', 'column' => 'expiry_date'],
                ],
            ],
            'recibo_rc' => ['fields' => $receiptFields],
            'poliza_accidentes' => [
                'fields' => [
                    ['key' => 'policy_number', 'type' => 'text', 'label' => 'doc_fields.policy_number'],
                    ['key' => 'insurer', 'type' => 'text', 'label' => 'doc_fields.insurer'],
                    ['key' => 'workers_covered', 'type' => 'number', 'label' => 'doc_fields.workers_covered'],
                    ['key' => 'capital_insured', 'type' => 'money', 'label' => 'doc_fields.capital_insured'],
                    ['key' => 'start_date', 'type' => 'date', 'label' => 'doc_fields.start_date', 'column' => 'issue_date'],
                    ['key' => 'end_date', 'type' => 'date', 'label' => 'doc_fields.end_date', 'column' => 'expiry_date'],
                ],
            ],
            'recibo_accidentes' => ['fields' => $receiptFields],
            'certificado_spa' => [
                'fields' => [
                    ['key' => 'spa_company', 'type' => 'text', 'label' => 'doc_fields.spa_company'],
                    ['key' => 'spa_nif', 'type' => 'text', 'label' => 'doc_fields.spa_nif'],
                    ['key' => 'contract_number', 'type' => 'text', 'label' => 'doc_fields.contract_number'],
                    ['key' => 'disciplines', 'type' => 'checkbox_group', 'label' => 'doc_fields.disciplines', 'options' => ['seguridad', 'higiene', 'ergonomia', 'vigilancia']],
                    ['key' => 'start_date', 'type' => 'date', 'label' => 'doc_fields.start_date', 'column' => 'issue_date'],
                    ['key' => 'end_date', 'type' => 'date', 'label' => 'doc_fields.end_date', 'column' => 'expiry_date'],
                ],
            ],
            'recibo_spa' => ['fields' => [
                ['key' => 'receipt_number', 'type' => 'text', 'label' => 'doc_fields.receipt_number'],
                ['key' => 'amount_paid', 'type' => 'money', 'label' => 'doc_fields.amount_paid'],
                ['key' => 'payment_date', 'type' => 'date', 'label' => 'doc_fields.payment_date', 'column' => 'issue_date'],
                ['key' => 'period_covered', 'type' => 'text', 'label' => 'doc_fields.period_covered'],
            ]],

            // ── Periódicos ────────────────────────────────────────────────────
            'evaluacion_riesgos' => ['fields' => [
                ['key' => 'reference_number', 'type' => 'text', 'label' => 'doc_fields.reference_number'],
                ['key' => 'prepared_by', 'type' => 'text', 'label' => 'doc_fields.prepared_by'],
                ['key' => 'prl_technician', 'type' => 'text', 'label' => 'doc_fields.prl_technician'],
                ['key' => 'risk_level', 'type' => 'select', 'label' => 'doc_fields.risk_level', 'options' => ['bajo', 'medio', 'alto']],
                ['key' => 'elaboration_date', 'type' => 'date', 'label' => 'doc_fields.elaboration_date', 'column' => 'issue_date'],
                ['key' => 'next_review_date', 'type' => 'date', 'label' => 'doc_fields.next_review_date', 'column' => 'expiry_date'],
            ]],
            'rea' => ['fields' => [
                ['key' => 'rea_number', 'type' => 'text', 'label' => 'doc_fields.rea_number'],
                ['key' => 'region', 'type' => 'text', 'label' => 'doc_fields.region'],
                ['key' => 'labor_authority', 'type' => 'text', 'label' => 'doc_fields.labor_authority'],
                ['key' => 'registration_date', 'type' => 'date', 'label' => 'doc_fields.registration_date', 'column' => 'issue_date'],
                ['key' => 'rea_expiry', 'type' => 'date', 'label' => 'doc_fields.end_date', 'column' => 'expiry_date'],
            ]],

            'documento_mutua' => [
                'fields' => [
                    ['key' => 'mutua_name', 'type' => 'text', 'label' => 'doc_fields.mutua_name'],
                    ['key' => 'associate_number', 'type' => 'text', 'label' => 'doc_fields.associate_number'],
                    ['key' => 'ccc', 'type' => 'ccc', 'label' => 'doc_fields.ccc'],
                    ['key' => 'mutua_coverage', 'type' => 'select', 'label' => 'doc_fields.mutua_coverage', 'options' => ['at_ep', 'at', 'other']],
                    ['key' => 'medical_center', 'type' => 'textarea', 'label' => 'doc_fields.medical_center'],
                    ['key' => 'start_date', 'type' => 'date', 'label' => 'doc_fields.start_date', 'column' => 'issue_date'],
                ],
            ],

            // ── Ciclo mensual ─────────────────────────────────────────────────
            // TGSS/AEAT: monthly upload cadence AND a valid_until date — the scan
            // alerts on whichever fires first (Q1). valid_until binds to
            // expiry_date so the 30-day check rides the existing engine.
            'certificado_ss' => ['fields' => [
                ['key' => 'certificate_number', 'type' => 'text', 'label' => 'doc_fields.certificate_number'],
                ['key' => 'ccc', 'type' => 'ccc', 'label' => 'doc_fields.ccc'],
                ['key' => 'issuing_office', 'type' => 'text', 'label' => 'doc_fields.issuing_office_tgss'],
                ['key' => 'issue_date', 'type' => 'date', 'label' => 'doc_fields.issue_date', 'column' => 'issue_date'],
                ['key' => 'valid_until', 'type' => 'date', 'label' => 'doc_fields.valid_until', 'column' => 'expiry_date'],
            ]],
            'certificado_hacienda' => ['fields' => [
                ['key' => 'certificate_number', 'type' => 'text', 'label' => 'doc_fields.certificate_number'],
                ['key' => 'nif_covered', 'type' => 'text', 'label' => 'doc_fields.nif_covered'],
                ['key' => 'issuing_office', 'type' => 'text', 'label' => 'doc_fields.issuing_office_aeat'],
                ['key' => 'issue_date', 'type' => 'date', 'label' => 'doc_fields.issue_date', 'column' => 'issue_date'],
                ['key' => 'valid_until', 'type' => 'date', 'label' => 'doc_fields.valid_until', 'column' => 'expiry_date'],
            ]],
            'ita' => ['fields' => [
                ['key' => 'ccc', 'type' => 'ccc', 'label' => 'doc_fields.ccc'],
                ['key' => 'period', 'type' => 'month_year', 'label' => 'doc_fields.period'],
                ['key' => 'workers_registered', 'type' => 'number', 'label' => 'doc_fields.workers_registered'],
                ['key' => 'issuing_office', 'type' => 'text', 'label' => 'doc_fields.issuing_office_tgss'],
                ['key' => 'report_date', 'type' => 'date', 'label' => 'doc_fields.report_date', 'column' => 'issue_date'],
            ]],
            'rnt' => ['fields' => [
                ['key' => 'period', 'type' => 'month_year', 'label' => 'doc_fields.period'],
                ['key' => 'workers_included', 'type' => 'number', 'label' => 'doc_fields.workers_included'],
                ['key' => 'ccc', 'type' => 'ccc', 'label' => 'doc_fields.ccc'],
                ['key' => 'validated_tgss', 'type' => 'select', 'label' => 'doc_fields.validated_tgss', 'options' => ['yes', 'no']],
                ['key' => 'submission_date', 'type' => 'date', 'label' => 'doc_fields.submission_date', 'column' => 'issue_date'],
            ]],
            'rlc' => ['fields' => [
                ['key' => 'period', 'type' => 'month_year', 'label' => 'doc_fields.period'],
                ['key' => 'total_contributions', 'type' => 'money', 'label' => 'doc_fields.total_contributions'],
                ['key' => 'ccc', 'type' => 'ccc', 'label' => 'doc_fields.ccc'],
                ['key' => 'payment_reference', 'type' => 'text', 'label' => 'doc_fields.payment_reference'],
                ['key' => 'payment_date', 'type' => 'date', 'label' => 'doc_fields.payment_date', 'column' => 'issue_date'],
            ]],
            'recibo_rlc' => ['fields' => [
                ['key' => 'receipt_number', 'type' => 'text', 'label' => 'doc_fields.receipt_number'],
                ['key' => 'amount_paid', 'type' => 'money', 'label' => 'doc_fields.amount_paid'],
                ['key' => 'payment_date', 'type' => 'date', 'label' => 'doc_fields.payment_date', 'column' => 'issue_date'],
                ['key' => 'period_covered', 'type' => 'text', 'label' => 'doc_fields.period_covered'],
            ]],
            'justificante_salarios' => ['fields' => [
                ['key' => 'period', 'type' => 'month_year', 'label' => 'doc_fields.period'],
                ['key' => 'workers_count', 'type' => 'number', 'label' => 'doc_fields.workers_count'],
                ['key' => 'total_payroll', 'type' => 'money', 'label' => 'doc_fields.total_payroll'],
                ['key' => 'payroll_payment_date', 'type' => 'date', 'label' => 'doc_fields.payroll_payment_date', 'column' => 'issue_date'],
            ]],
        ];
    }

    /**
     * Field defs for one document type of any entity ([] for unknown/custom).
     * Company types carry the bespoke client spec; employee + project types get
     * generic date fields derived from their cfg (issue_date, plus expiry_date
     * when the type is expiry-tracked) so the smart panel + version history +
     * repeatable contacts work everywhere without inventing per-type fields.
     *
     * @return list<array{key: string, type: string, label: string, column?: string, options?: list<string>}>
     */
    public static function fieldsFor(string $entityType, string $typeKey): array
    {
        return match ($entityType) {
            'company' => self::companyFields()[$typeKey]['fields'] ?? [],
            'employee' => self::genericDateFields(self::employeeTypeCfg($typeKey)),
            'project' => self::genericDateFields(self::projectTypeCfg($typeKey)),
            'client' => self::genericDateFields(self::client()['client'][$typeKey] ?? []),
            'vendor' => self::genericDateFields(self::vendor()['vendor'][$typeKey] ?? []),
            default => [],
        };
    }

    /**
     * The metadata keys a type stores in the JSON blob: every field that is
     * neither column-bound nor the read-only CCC. The write whitelist the Form
     * Request validates against — anything else is rejected.
     *
     * @return list<string>
     */
    public static function metadataKeysFor(string $entityType, string $typeKey): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['key'],
            array_filter(
                self::fieldsFor($entityType, $typeKey),
                static fn (array $f): bool => ! isset($f['column']) && $f['type'] !== 'ccc',
            ),
        ));
    }

    /**
     * Column-bound fields: metadata field key => Document column. Lets the
     * controller route start/end/valid_until dates onto issue_date/expiry_date.
     *
     * @return array<string, string>
     */
    public static function columnBindingsFor(string $entityType, string $typeKey): array
    {
        $map = [];

        foreach (self::fieldsFor($entityType, $typeKey) as $field) {
            if (isset($field['column'])) {
                $map[$field['key']] = $field['column'];
            }
        }

        return $map;
    }

    /**
     * A flat {typeKey: {fields: [...]}} map for one entity — feeds the upload
     * modal's dynamic field form.
     *
     * @return array<string, array{fields: list<array<string, mixed>>}>
     */
    public static function fieldDefsMap(string $entityType): array
    {
        $keys = match ($entityType) {
            'company' => self::companyKeys(),
            'employee' => self::employeeKeys(),
            'project' => self::projectKeys(),
            'client' => self::clientKeys(),
            'vendor' => self::vendorKeys(),
            default => [],
        };

        $map = [];

        foreach ($keys as $key) {
            $map[$key] = ['fields' => self::fieldsFor($entityType, $key)];
        }

        return $map;
    }

    /**
     * Generic date fields for a non-company type: always an issue date, plus an
     * expiry date when the type is expiry-tracked. Column-bound so the alert
     * engine keeps reading expiry_date.
     *
     * @param  array{flag?: bool, file?: bool, expiry?: bool}  $cfg
     * @return list<array{key: string, type: string, label: string, column: string}>
     */
    private static function genericDateFields(array $cfg): array
    {
        $fields = [
            ['key' => 'issue_date', 'type' => 'date', 'label' => 'documents.issue_date', 'column' => 'issue_date'],
        ];

        if ($cfg['expiry'] ?? false) {
            $fields[] = ['key' => 'expiry_date', 'type' => 'date', 'label' => 'documents.expiry_date', 'column' => 'expiry_date'];
        }

        return $fields;
    }

    /**
     * @return array{flag?: bool, file?: bool, expiry?: bool}
     */
    private static function employeeTypeCfg(string $typeKey): array
    {
        foreach (self::employee() as $types) {
            if (isset($types[$typeKey])) {
                return $types[$typeKey];
            }
        }

        return [];
    }

    /**
     * @return array{flag?: bool, file?: bool, expiry?: bool}
     */
    private static function projectTypeCfg(string $typeKey): array
    {
        return self::project()['project'][$typeKey] ?? [];
    }

    /**
     * Company-scoped wrappers (kept for existing callers + tests).
     *
     * @return list<array{key: string, type: string, label: string, column?: string, options?: list<string>}>
     */
    public static function companyFieldDefs(string $typeKey): array
    {
        return self::fieldsFor('company', $typeKey);
    }

    /**
     * @return list<string>
     */
    public static function companyMetadataKeys(string $typeKey): array
    {
        return self::metadataKeysFor('company', $typeKey);
    }

    /**
     * @return array<string, string>
     */
    public static function companyColumnBindings(string $typeKey): array
    {
        return self::columnBindingsFor('company', $typeKey);
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
     * Client document slots (shared entity, company-owned files). Sensible
     * defaults for a Spanish construction CRM; each gets the generic date +
     * repeatable-contacts smart panel. Adjust the set as the client confirms.
     *
     * @return array<string, array<string, array{flag: bool, file: bool, expiry: bool}>>
     */
    public static function client(): array
    {
        return [
            'client' => [
                'contrato' => ['flag' => false, 'file' => true, 'expiry' => true],
                'pedido' => ['flag' => false, 'file' => true, 'expiry' => false],
                'datos_fiscales' => ['flag' => false, 'file' => true, 'expiry' => false],
                'certificado_bancario' => ['flag' => false, 'file' => true, 'expiry' => false],
                'seguro' => ['flag' => false, 'file' => true, 'expiry' => true],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function clientKeys(): array
    {
        return array_keys(self::client()['client']);
    }

    /**
     * The upload `category` for a type — the value the store endpoint expects.
     * Employee types resolve to their registry group (personal/employment/
     * prevencion); the others map straight to the entity name.
     */
    public static function categoryFor(string $entityType, string $typeKey): string
    {
        if ($entityType !== 'employee') {
            return $entityType;
        }

        foreach (self::employee() as $category => $types) {
            if (isset($types[$typeKey])) {
                return $category;
            }
        }

        return 'personal';
    }

    /**
     * Vendor document slots — a supplier/subcontractor's compliance paperwork
     * (RC insurance, TGSS/AEAT clearances, REA…). Shared entity, company-owned.
     *
     * @return array<string, array<string, array{flag: bool, file: bool, expiry: bool}>>
     */
    public static function vendor(): array
    {
        return [
            'vendor' => [
                'contrato' => ['flag' => false, 'file' => true, 'expiry' => true],
                'seguro_rc' => ['flag' => false, 'file' => true, 'expiry' => true],
                'certificado_ss' => ['flag' => false, 'file' => true, 'expiry' => false],
                'certificado_aeat' => ['flag' => false, 'file' => true, 'expiry' => false],
                'rea' => ['flag' => false, 'file' => true, 'expiry' => true],
                'datos_fiscales' => ['flag' => false, 'file' => true, 'expiry' => false],
                'certificado_bancario' => ['flag' => false, 'file' => true, 'expiry' => false],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function vendorKeys(): array
    {
        return array_keys(self::vendor()['vendor']);
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
