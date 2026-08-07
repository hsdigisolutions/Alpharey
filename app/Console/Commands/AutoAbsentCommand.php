<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly auto-absent sweep (23:59 Madrid). For every active employee in every
 * active company, if the day was a WEEKDAY and no attendance was recorded, book
 * an automatic absence (0 hours, 0 pay, flagged is_auto_generated) so payroll
 * and reports see the day.
 *
 * Deliberately skipped:
 *  - Weekends (Sat/Sun) — never an auto-absence.
 *  - Employees who already have a row for the day (present, late, leave, or a
 *    manual absence). Approved leave writes a Leave attendance row, so a worker
 *    on approved leave is covered by this check.
 *  - New hires whose joining_date is after the day.
 *
 * An admin can override any auto-absence by editing the cell.
 */
class AutoAbsentCommand extends Command
{
    protected $signature = 'attendance:auto-absent {--date= : Run for a specific Y-m-d instead of today}';

    protected $description = 'Book automatic absences for active employees with no record on a weekday';

    public function handle(): int
    {
        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::now('Europe/Madrid');
        $dateStr = $date->toDateString();

        if ($date->isWeekend()) {
            $this->info("Weekend ({$dateStr}) — no auto-absences.");

            return self::SUCCESS;
        }

        // Active companies = not soft-deleted (Company uses SoftDeletes).
        $activeCompanyIds = Company::query()->pluck('id');

        $employees = Employee::query()
            ->withoutGlobalScope(CompanyScope::class) // keeps SoftDeletes in force
            ->where('active', true)
            ->whereIn('company_id', $activeCompanyIds)
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $dateStr))
            ->get(['id', 'company_id']);

        $already = Attendance::query()->withoutGlobalScopes()
            ->where('date', $dateStr)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->pluck('employee_id')
            ->flip();

        $created = 0;

        foreach ($employees as $employee) {
            if (isset($already[$employee->id])) {
                continue;
            }

            $absence = new Attendance([
                'employee_id' => $employee->id,
                'date' => $dateStr,
                'mode' => 'project_based',
                'status' => 'absent',
                'hours_worked' => '0',
                'overtime_hours' => '0',
                'total_amount' => '0',
                'notes' => 'Ausencia automática — sin registro',
            ]);
            $absence->company_id = $employee->company_id;
            $absence->is_auto_generated = true; // not fillable — set here only
            $absence->is_weekend = false;
            $absence->save();

            $created++;
        }

        $this->info("Auto-absent {$dateStr}: {$created} absence(s) created.");

        return self::SUCCESS;
    }
}
