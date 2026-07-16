<?php

namespace App\Support;

use App\Models\LockedPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single authority on closed months.
 *
 * REQUIREMENTS.md Screen 12: once a period is locked, attendance and payroll
 * edits for that month are rejected **system-wide** — not just on the payroll
 * screen. Every write path that touches a dated money/attendance record calls
 * assert() here, so there is exactly one place the rule lives.
 */
class PeriodLock
{
    /**
     * @var array<string, bool> per-request memo — the guard is hit once per
     *                          attendance row during a bulk import
     */
    private array $memo = [];

    /**
     * `Y-m` of any date-ish value.
     */
    public static function month(string|Carbon $date): string
    {
        return $date instanceof Carbon
            ? $date->format('Y-m')
            : Carbon::parse($date)->format('Y-m');
    }

    public function isLocked(int $companyId, string|Carbon $date): bool
    {
        $month = self::month($date);
        $key = $companyId.'|'.$month;

        return $this->memo[$key] ??= LockedPeriod::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('month', $month)
            ->exists();
    }

    /**
     * Reject a write into a closed month.
     *
     * @throws ValidationException
     */
    public function assertOpen(int $companyId, string|Carbon $date, string $field = 'date'): void
    {
        if (! $this->isLocked($companyId, $date)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('ui.payroll.period_locked', ['month' => self::month($date)]),
        ]);
    }

    /**
     * Drop the memo — the lock/unlock actions call this so a lock taken in the
     * same request is seen immediately.
     */
    public function forget(): void
    {
        $this->memo = [];
    }
}
