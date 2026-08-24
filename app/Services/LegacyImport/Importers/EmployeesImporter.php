<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\Company;
use App\Models\Employee;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy employees → new employees (DATA_MIGRATION.md §3.6). The legacy
 * system was single-company, so all rows attach to Company 1 (admins
 * reassign afterward). Wage-source normalization: legacy wage_type value
 * "meter" → "per_meter". Encrypted casts + blind index apply because we
 * write through the Eloquent model. Numeric ids are preserved so uploaded
 * document folders (employees/{id}/…) stay aligned.
 */
class EmployeesImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'employees';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('employees', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('employees')->orderBy('id')->chunk(200, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $wageType = $this->normalizeWageType($row->wage_type ?? null);

                // acrossAllCompanies() opts out of the tenancy scope (importers
                // run without an authenticated user — the scope would deny all).
                $exists = Employee::acrossAllCompanies()
                    ->where('company_id', $defaultCompanyId)
                    ->where('employee_code', $row->employee_code ?? '')
                    ->exists();

                if ($exists && ($row->employee_code ?? '') !== '') {
                    $this->exception('employees', $row->id, 'Duplicate employee_code in target company', [
                        'employee_code' => $row->employee_code,
                    ]);

                    continue;
                }

                $employee = new Employee([
                    'full_name' => $row->full_name ?? $row->name ?? 'Sin nombre',
                    'nif' => $row->nif ?? null,
                    'email' => $row->email ?? null,
                    'mobile' => $row->mobile ?? $row->phone ?? null,
                    'city' => $row->city ?? null,
                    'address' => $row->address ?? null,
                    'department' => $row->department ?? null,
                    'designation' => $row->designation ?? null,
                    'joining_date' => $row->joining_date ?? null,
                    'leaving_date' => $row->leaving_date ?? null,
                    'active' => (bool) ($row->active ?? $row->is_active ?? true),
                    'is_contracted' => (bool) ($row->is_contracted ?? false),
                    'wage_type' => $wageType,
                    'wage_rate' => isset($row->wage_rate) ? (string) $row->wage_rate : null,
                    'base_salary' => isset($row->base_salary) ? (string) $row->base_salary : null,
                    'daily_wage' => isset($row->daily_wage) ? (string) $row->daily_wage : null,
                    'per_meter_rate' => isset($row->per_meter_rate) ? (string) $row->per_meter_rate : null,
                    'commission_percent' => $row->commission_percent ?? null,
                    'iban' => $row->iban ?? null,
                    'bank_name' => $row->bank_name ?? null,
                ]);
                $employee->company_id = $defaultCompanyId;
                $employee->employee_code = ($row->employee_code ?? '') !== ''
                    ? $row->employee_code
                    : Employee::nextCode();
                $employee->save();

                $this->recordMapping($row->id, $employee->id);
                $this->imported++;
            }
        });
    }

    private function normalizeWageType(?string $legacy): ?string
    {
        return match ($legacy) {
            'meter' => 'per_meter',
            'daily', 'hourly', 'monthly', 'per_meter' => $legacy,
            default => null,
        };
    }
}
