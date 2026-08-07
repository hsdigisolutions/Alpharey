<?php

use App\Models\Employee;
use App\Models\EmployeeWageRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

/**
 * Seed the wage history for the world as it stands today.
 *
 *  1. Reconcile any legacy rows: before this feature every rate was left open
 *     (effective_to was a brand-new nullable column). Order each employee's
 *     rows by effective_from and close all but the newest, so exactly one
 *     stays open — the single-open invariant the service will keep from here.
 *
 *  2. Seed the untouched: every employee who has a wage set but no rate row
 *     gets one open record from their joining date (or 2020-01-01), carrying
 *     their current rate + type. This gives every existing worker a history
 *     starting from day one, so attendance rate lookups never miss.
 *
 * Runs through the Eloquent models (the `rate` column is an encrypted cast);
 * global scopes are dropped because a migration has no company/auth context.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reconcileLegacyRows();
        $this->seedMissing();
    }

    private function reconcileLegacyRows(): void
    {
        $byEmployee = EmployeeWageRate::query()
            ->withoutGlobalScopes()
            ->orderBy('employee_id')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        foreach ($byEmployee as $rows) {
            $ordered = $rows->values();
            $count = $ordered->count();

            foreach ($ordered as $i => $rate) {
                $isLatest = $i === $count - 1;

                if ($isLatest) {
                    // Newest row is the current, open one.
                    if ($rate->effective_to !== null || $rate->is_default !== true) {
                        $rate->effective_to = null;
                        $rate->is_default = true;
                        $rate->saveQuietly();
                    }

                    continue;
                }

                // Historical row: close it the day before the next one opens.
                $next = $ordered->get($i + 1);
                $from = Carbon::parse($rate->effective_from->toDateString());
                $nextFrom = Carbon::parse($next->effective_from->toDateString());
                $closeAt = $nextFrom->copy()->subDay();

                if ($closeAt->lt($from)) {
                    $closeAt = $from->copy(); // same-day edits: a zero-length range
                }

                $rate->effective_to = $closeAt;
                $rate->is_default = false;
                $rate->saveQuietly();
            }
        }
    }

    private function seedMissing(): void
    {
        $haveRates = EmployeeWageRate::query()->withoutGlobalScopes()
            ->distinct()->pluck('employee_id')->flip();

        $employees = Employee::query()->withoutGlobalScopes()->get();

        foreach ($employees as $employee) {
            if (isset($haveRates[$employee->id])) {
                continue;
            }

            if ($employee->wage_type === null) {
                continue;
            }

            $amount = match ($employee->wage_type->value) {
                'hourly' => $employee->getAttribute('wage_rate'),
                'daily' => $employee->getAttribute('daily_wage'),
                'monthly' => $employee->getAttribute('base_salary'),
                'per_meter' => $employee->getAttribute('per_meter_rate'),
            };

            if ($amount === null || (float) $amount <= 0) {
                continue;
            }

            $from = $employee->joining_date?->toDateString() ?? '2020-01-01';

            $rate = new EmployeeWageRate([
                'wage_type' => $employee->wage_type->value,
                'rate' => (string) $amount,
                'effective_from' => $from,
                'is_default' => true,
            ]);
            $rate->effective_to = null;
            $rate->employee_id = $employee->id;
            $rate->company_id = $employee->company_id;
            $rate->saveQuietly();
        }
    }

    public function down(): void
    {
        // Data backfill — nothing to reverse.
    }
};
