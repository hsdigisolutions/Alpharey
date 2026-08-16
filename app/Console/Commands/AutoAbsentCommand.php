<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Notifications\NotificationDispatcher;
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
        $iso = (int) $date->dayOfWeekIso; // 1=Mon … 7=Sun

        // Active companies = not soft-deleted (Company uses SoftDeletes).
        $activeCompanyIds = Company::query()->pluck('id');

        // Per-company working days (default Mon–Fri). A day only produces
        // absences for companies that count it as a working day.
        $attendance = app(AttendanceService::class);
        $workingByCompany = [];
        $anyWorking = false;
        foreach ($activeCompanyIds as $cid) {
            $days = $attendance->workingDays((int) $cid);
            $workingByCompany[(int) $cid] = $days;
            if (in_array($iso, $days, true)) {
                $anyWorking = true;
            }
        }

        if (! $anyWorking) {
            $this->info("{$dateStr} is a non-working day for every company — no auto-absences.");

            return self::SUCCESS;
        }

        $employees = Employee::query()
            ->withoutGlobalScope(CompanyScope::class) // keeps SoftDeletes in force
            ->where('active', true)
            ->whereIn('company_id', $activeCompanyIds)
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $dateStr))
            // A reactivated worker accrues absences only from active_since — a
            // backdated sweep never fills the inactive spell (same rule as the
            // computed calendars in AttendanceAbsence).
            ->where(fn ($q) => $q->whereNull('active_since')->orWhere('active_since', '<=', $dateStr))
            ->get(['id', 'company_id']);

        $already = Attendance::query()->withoutGlobalScopes()
            ->where('date', $dateStr)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->pluck('employee_id')
            ->flip();

        $created = 0;
        /** @var array<int, int> $perCompany */
        $perCompany = [];

        foreach ($employees as $employee) {
            if (isset($already[$employee->id])) {
                continue;
            }

            // Skip if this weekday is not a working day for the worker's company.
            if (! in_array($iso, $workingByCompany[(int) $employee->company_id] ?? [1, 2, 3, 4, 5], true)) {
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

            $perCompany[$employee->company_id] = ($perCompany[$employee->company_id] ?? 0) + 1;
            $created++;
        }

        // One summary per company that had auto-absences → its Company Admins.
        $dispatcher = app(NotificationDispatcher::class);
        foreach ($perCompany as $companyId => $count) {
            $dispatcher->dispatch(NotificationType::AutoAbsent, $companyId, [
                'title_es' => "Ausencias automáticas ({$dateStr}): {$count} trabajador(es)",
                'title_en' => "Automatic absences ({$dateStr}): {$count} worker(s)",
                'entity' => $dateStr, 'url' => '/attendance',
            ]);
        }

        $this->info("Auto-absent {$dateStr}: {$created} absence(s) created.");

        return self::SUCCESS;
    }
}
