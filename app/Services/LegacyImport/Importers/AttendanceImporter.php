<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\Company;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy attendance → new attendance (DATA_MIGRATION.md §3, Phase 4 row).
 * Attendance snapshots migrate UNTOUCHED — they are historical facts, not
 * recomputed from the current employee rate (the legacy `hours` column maps
 * to `hours_worked`; the legacy per-row wage snapshot carries over). Rows
 * attach to Company 1 (single-company legacy). employee_id/project_id are
 * remapped through the employees/projects id maps.
 */
class AttendanceImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'attendance';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('attendance', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('attendance')->orderBy('id')->chunk(500, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $employeeId = $this->newIdFor($row->employee_id, 'employees');

                if ($employeeId === null) {
                    $this->exception('attendance', $row->id, 'Employee not imported — run the employees importer first', [
                        'legacy_employee_id' => $row->employee_id,
                    ]);

                    continue;
                }

                $projectId = $row->project_id !== null ? $this->newIdFor($row->project_id, 'projects') : null;

                // hours_worked ← legacy hours_worked, or the legacy `hours` alias
                $hours = $row->hours_worked ?? $row->hours ?? 0;

                $attendance = new Attendance([
                    'employee_id' => $employeeId,
                    'project_id' => $projectId,
                    'date' => $row->date,
                    'mode' => $this->normalizeMode($row->mode ?? null),
                    'check_in' => $row->check_in ?? null,
                    'check_out' => $row->check_out ?? null,
                    'hours_worked' => $hours,
                    'overtime_hours' => $row->overtime_hours ?? 0,
                    'status' => $this->normalizeStatus($row->status ?? null),
                    'total_amount' => $row->total_amount ?? 0,
                    'is_paid' => (bool) ($row->is_paid ?? false),
                    'notes' => $row->notes ?? null,
                ]);
                $attendance->company_id = $defaultCompanyId;
                // Historical snapshots carried over verbatim (not recomputed).
                // wage_type is the one exception: the legacy dump spells it
                // `day`/`hour`, this schema `daily`/`hourly` — the same fact in
                // a different spelling, so we translate the label without
                // touching the frozen rate. Found against the real dump: 4 888
                // `day` rows + 23 `hour` rows died on the WageType cast.
                $attendance->wage_type_snapshot = $this->normalizeWageType($row->wage_type ?? $row->wage_type_snapshot ?? null);
                $attendance->wage_rate_snapshot = $row->wage_rate ?? $row->wage_rate_snapshot ?? null;
                $attendance->hourly_rate_snapshot = $row->hourly_rate ?? $row->hourly_rate_snapshot ?? null;
                $attendance->save();

                $this->recordMapping($row->id, $attendance->id);
                $this->imported++;
            }
        });
    }

    private function normalizeMode(?string $legacy): string
    {
        return $legacy === 'project_based' ? 'project_based' : 'hourly';
    }

    private function normalizeStatus(?string $legacy): string
    {
        return in_array($legacy, ['present', 'absent', 'late', 'early_leave', 'leave'], true) ? $legacy : 'present';
    }

    /**
     * Legacy `day`/`hour` → this schema's `daily`/`hourly`. Already-correct
     * values (and per_meter/monthly) pass through; an unknown value is left
     * null rather than guessed — an unreadable snapshot must not invent a rate.
     */
    private function normalizeWageType(?string $legacy): ?WageType
    {
        return match ($legacy) {
            'day', 'daily' => WageType::Daily,
            'hour', 'hourly' => WageType::Hourly,
            'month', 'monthly' => WageType::Monthly,
            'meter', 'per_meter' => WageType::PerMeter,
            default => null,
        };
    }
}
