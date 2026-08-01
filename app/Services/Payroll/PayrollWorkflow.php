<?php

namespace App\Services\Payroll;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\WorkerExpenseStatus;
use App\Models\Advance;
use App\Models\LockedPeriod;
use App\Models\Payroll;
use App\Models\WorkerExpense;
use App\Support\PeriodLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Screen 12 workflow: Calculate → review → Approve All → mark paid →
 * Lock Period.
 *
 * Marking a payroll paid also settles the advances it deducted — that is the
 * moment the money actually left, so `deducted` is set here and nowhere else.
 */
class PayrollWorkflow
{
    public function __construct(private readonly PeriodLock $lock) {}

    /**
     * Approve every pending row in the month.
     *
     * @return int rows approved
     */
    public function approveAll(int $companyId, string $month): int
    {
        $this->lock->assertOpen($companyId, $month.'-01', 'month');

        return Payroll::query()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->where('status', PayrollStatus::Pending->value)
            ->update([
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
    }

    /**
     * Mark one payroll paid and settle the advances it deducted.
     */
    public function markPaid(Payroll $payroll, ?PaymentMethod $method = null): Payroll
    {
        $this->lock->assertOpen($payroll->company_id, $payroll->month.'-01', 'month');

        return DB::transaction(function () use ($payroll, $method): Payroll {
            $payroll->status = PayrollStatus::Paid;
            $payroll->paid_at = now();

            if ($method !== null) {
                $payroll->payment_method = $method;
            }

            $payroll->save();

            // The advances this row deducted are now genuinely settled.
            Advance::query()->withoutGlobalScopes()
                ->where('employee_id', $payroll->employee_id)
                ->where('payroll_month', $payroll->month)
                ->where('status', AdvanceStatus::Approved->value)
                ->update(['status' => AdvanceStatus::Deducted->value]);

            // Worker PWA expenses included in this payroll are stamped so a
            // recalculation does not double-count them.
            WorkerExpense::query()->withoutGlobalScopes()
                ->where('employee_id', $payroll->employee_id)
                ->where('status', WorkerExpenseStatus::Approved->value)
                ->whereNull('payroll_id')
                ->whereMonth('date', substr($payroll->month, 5, 2))
                ->whereYear('date', substr($payroll->month, 0, 4))
                ->update(['payroll_id' => $payroll->id]);

            return $payroll;
        });
    }

    /**
     * Close a month. From here on every dated attendance/payroll write for
     * this company + month is rejected by PeriodLock.
     */
    public function lockPeriod(int $companyId, string $month): LockedPeriod
    {
        $unpaid = Payroll::query()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->where('status', PayrollStatus::Pending->value)
            ->exists();

        if ($unpaid) {
            throw ValidationException::withMessages([
                'month' => __('ui.payroll.lock_blocked'),
            ]);
        }

        $period = LockedPeriod::query()->firstOrNew(
            ['company_id' => $companyId, 'month' => $month],
        );
        $period->company_id = $companyId;
        $period->month = $month;
        $period->locked_by = Auth::id();
        $period->locked_at = now();
        $period->save();

        $this->lock->forget(); // the memo must see this immediately

        return $period;
    }

    public function unlockPeriod(int $companyId, string $month): void
    {
        LockedPeriod::query()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->delete();

        $this->lock->forget();
    }
}
