<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Support\PeriodLock;
use Illuminate\Console\Command;

/**
 * Repair jornadas left at total_amount = 0 by the day-type back-fill migration.
 *
 * That migration reclassified old zero-priced rows to day_type = full/half but
 * never re-priced them, so their frozen total stayed 0 while the rate snapshot
 * kept a stale value. Payroll masks it with a re-derivation, but the attendance
 * tab and the daily P&L read the raw total and show 0. This re-prices each such
 * row from the rate in force on its date (wage history else the live rate), so
 * the correct total lands everywhere — once.
 *
 * Safe: skips manual overrides, already-paid rows, and any row in a locked month.
 * Idempotent — a correctly-priced row (total ≠ 0) is never touched. `--dry-run`
 * reports the count without writing.
 */
class RepriceZeroAttendanceCommand extends Command
{
    protected $signature = 'attendance:reprice-zero {--dry-run : Report what would change without writing}';

    protected $description = 'Re-price worked full/half attendance days left at 0 by the day-type back-fill.';

    public function handle(AttendanceService $attendance, PeriodLock $lock): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('day_type', ['full', 'half'])
            ->where('total_amount', 0)
            ->whereIn('status', ['present', 'late', 'early_leave'])
            ->where('manual_wage_override', false)
            ->where('is_paid', false)
            ->get();

        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            // Never rewrite a row in a month payroll has closed.
            if ($lock->isLocked($row->company_id, $row->date->toDateString())) {
                $skipped++;

                continue;
            }

            $employee = Employee::query()->withoutGlobalScope(CompanyScope::class)->find($row->employee_id);

            if ($employee === null) {
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                $attendance->recalculateRow($row, $employee);
            }

            $fixed++;
        }

        $verb = $dryRun ? 'would re-price' : 're-priced';
        $this->info("{$verb} {$fixed} row(s); skipped {$skipped} (locked / missing employee).");

        return self::SUCCESS;
    }
}
