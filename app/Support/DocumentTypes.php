<?php

namespace App\Support;

/**
 * The canonical document-type registry (REQUIREMENTS.md Screens 04 + 06):
 * the 13 official Spanish company documents and the employee document sets.
 * Labels live in lang/{es,en}/ui.php under doc_types.* — this registry
 * defines structure only (frequency, file/flag/expiry expectations).
 */
class DocumentTypes
{
    /**
     * The 13 official company document types.
     *
     * @return array<string, array{frequency: string, expiry: bool}>
     */
    public static function company(): array
    {
        return [
            'cif_nif' => ['frequency' => 'one_time', 'expiry' => false],
            'escritura_constitucion' => ['frequency' => 'one_time', 'expiry' => false],
            'certificado_digital' => ['frequency' => 'annual', 'expiry' => true],
            'seguro_rc' => ['frequency' => 'annual', 'expiry' => true],
            'seguro_accidentes' => ['frequency' => 'annual', 'expiry' => true],
            'plan_prl' => ['frequency' => 'annual', 'expiry' => true],
            'licencia_actividad' => ['frequency' => 'varies', 'expiry' => true],
            'certificado_aeat' => ['frequency' => 'monthly', 'expiry' => false],
            'certificado_tgss' => ['frequency' => 'monthly', 'expiry' => false],
            'modelo_303' => ['frequency' => 'monthly', 'expiry' => false],
            'tc1_tc2' => ['frequency' => 'monthly', 'expiry' => false],
            'contratos_vigentes' => ['frequency' => 'on_change', 'expiry' => false],
            'formacion_art19' => ['frequency' => 'annual', 'expiry' => true],
        ];
    }

    /**
     * Employee document sets grouped by category (Screen 06 Tab 2).
     * flag = the Yes/No confirmation field; file = upload slot; expiry = date.
     *
     * @return array<string, array<string, array{flag: bool, file: bool, expiry: bool}>>
     */
    public static function employee(): array
    {
        return [
            'personal' => [
                'dni' => ['flag' => true, 'file' => true, 'expiry' => true],
                'nie' => ['flag' => true, 'file' => true, 'expiry' => true],
                'passport' => ['flag' => true, 'file' => true, 'expiry' => true],
                'driving_license' => ['flag' => true, 'file' => true, 'expiry' => true],
            ],
            'employment' => [
                'contract' => ['flag' => false, 'file' => true, 'expiry' => true],
                'salary_slip' => ['flag' => true, 'file' => false, 'expiry' => false],
                'social_security' => ['flag' => true, 'file' => true, 'expiry' => false],
                'bank_details' => ['flag' => true, 'file' => false, 'expiry' => false],
            ],
            'training' => [
                'formacion_art19' => ['flag' => true, 'file' => true, 'expiry' => true],
                'formacion_prl' => ['flag' => true, 'file' => true, 'expiry' => true],
                'certifications' => ['flag' => false, 'file' => true, 'expiry' => true],
            ],
            'medical' => [
                'medical_fitness' => ['flag' => true, 'file' => true, 'expiry' => true],
                'occupational_health' => ['flag' => true, 'file' => true, 'expiry' => true],
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
