<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\Company;
use App\Models\Payroll;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy payrolls → payrolls (DATA_MIGRATION.md).
 *
 * A historical payroll is a FACT: it is what was actually paid. The importer
 * therefore carries every figure over verbatim and never re-runs PayrollService
 * over old attendance — a rate that changed since would silently rewrite what a
 * worker was paid, which is the exact failure the wage snapshots exist to
 * prevent.
 *
 * Imported rows land as `paid` when the legacy row was paid, which also makes
 * them immune to a later recalculation (PayrollService skips paid rows).
 */
class PayrollsImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'payrolls';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('payrolls', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('payrolls')->orderBy('id')->chunk(500, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $employeeId = $this->newIdFor($row->employee_id, 'employees');

                if ($employeeId === null) {
                    $this->exception('payrolls', $row->id, 'Employee not imported — run the employees importer first', [
                        'legacy_employee_id' => $row->employee_id,
                    ]);

                    continue;
                }

                $month = $this->normalizeMonth($row);

                if ($month === null) {
                    $this->exception('payrolls', $row->id, 'Could not resolve the payroll month', [
                        'month' => $row->month ?? null,
                        'year' => $row->year ?? null,
                    ]);

                    continue;
                }

                $payroll = new Payroll([
                    'employee_id' => $employeeId,
                    'month' => $month,
                    'attendance_days' => (string) ($row->attendance_days ?? 0),
                    'attendance_hours' => (string) ($row->attendance_hours ?? 0),
                    'overtime_hours' => (string) ($row->overtime_hours ?? 0),
                    'wage_type' => $this->normalizeWageType($row->wage_type ?? null),
                    // every figure verbatim — historical pay is never recomputed
                    'wage_rate' => (string) ($row->wage_rate ?? 0),
                    'base_salary' => (string) ($row->base_salary ?? $row->salary ?? 0),
                    'days_amount' => (string) ($row->days_amount ?? 0),
                    'hours_amount' => (string) ($row->hours_amount ?? 0),
                    'overtime_pay' => (string) ($row->overtime_pay ?? 0),
                    'reimbursements' => (string) ($row->reimbursements ?? 0),
                    'project_expenses' => (string) ($row->project_expenses ?? 0),
                    'gross_pay' => (string) ($row->gross_pay ?? 0),
                    'advance_deductions' => (string) ($row->advance_deductions ?? 0),
                    'other_deductions' => (string) ($row->deductions ?? $row->other_deductions ?? 0),
                    'manual_additions' => (string) ($row->manual_additions ?? 0),
                    'net_amount' => (string) ($row->net_amount ?? 0),
                    'status' => ($row->status ?? null) === 'paid' ? 'paid' : 'pending',
                    'payment_method' => $row->payment_method ?? null,
                    'paid_at' => $row->paid_at ?? $row->payment_date ?? null,
                    'notes' => $row->notes ?? null,
                ]);
                $payroll->company_id = $defaultCompanyId;
                $payroll->save();

                $this->recordMapping($row->id, $payroll->id);
                $this->imported++;
            }
        });
    }

    /**
     * The new schema keys a payroll by 'YYYY-MM'. The legacy shape varies:
     * a date, a 'YYYY-MM' string, or separate month + year columns.
     */
    private function normalizeMonth(object $row): ?string
    {
        // The real dump keys the period in `payroll_month` ('YYYY-MM'); older
        // shapes used `month` (± a separate `year`) or a date column. Prefer
        // payroll_month, then fall back. Found against the real dump: all 156
        // rows carry payroll_month and none carry `month`, so the importer was
        // resolving nothing.
        $month = $row->payroll_month ?? $row->month ?? null;

        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month;
        }

        if (is_string($month) && preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $month, $m)) {
            return "{$m[1]}-{$m[2]}";
        }

        // separate numeric month + year columns
        if (is_numeric($month) && isset($row->year) && is_numeric($row->year)) {
            return sprintf('%04d-%02d', (int) $row->year, (int) $month);
        }

        $date = $row->period_start ?? $row->date ?? null;

        if (is_string($date) && preg_match('/^(\d{4})-(\d{2})/', $date, $m)) {
            return "{$m[1]}-{$m[2]}";
        }

        return null;
    }

    private function normalizeWageType(?string $legacy): ?string
    {
        if ($legacy === null) {
            return null;
        }

        // DATA_MIGRATION.md §3.6: 'meter' -> 'per_meter' everywhere
        $normalized = $legacy === 'meter' ? 'per_meter' : $legacy;

        return in_array($normalized, ['daily', 'hourly', 'monthly', 'per_meter'], true) ? $normalized : null;
    }
}
