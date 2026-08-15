<?php

namespace App\Services\Dashboard;

use App\Enums\AdvanceStatus;
use App\Enums\AttendanceStatus;
use App\Enums\DeploymentStatus;
use App\Enums\PayrollStatus;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeCallLog;
use App\Models\EmployeeDeployment;
use App\Models\Payroll;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Screen 15 — Today's Report. A live "what is happening right now" view for
 * one company; refreshed every 5 minutes from the client, so it is NOT cached
 * (unlike the dashboard) — a stale value here defeats the point.
 *
 * The one subtlety is deployed workers: a worker posted INTO this company logs
 * attendance under this company_id but belongs to another. They appear in the
 * table with a home-company column, matching the attendance grid (Phase 5).
 */
class TodayService
{
    private const WORKED = [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::EarlyLeave->value];

    /**
     * @param  array{search?: string, project?: int|null, status?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function for(int $companyId, array $filters = []): array
    {
        $today = Carbon::now()->toDateString();

        // Fetch today's attendance once, unfiltered — the filter dropdowns are
        // built from this stable set, then the visible rows are the filtered
        // subset. KPIs stay the day's headline totals (never filtered).
        $allToday = collect($this->attendanceToday($companyId, $today));

        $projectOptions = $allToday
            ->filter(fn (array $r): bool => $r['project_id'] !== null)
            ->unique('project_id')
            ->map(fn (array $r): array => ['id' => $r['project_id'], 'name' => $r['project']])
            ->sortBy('name')
            ->values()
            ->all();

        $rows = $this->applyAttendanceFilters($allToday, $filters);

        return [
            'kpis' => $this->kpis($companyId, $today),
            'attendance' => $rows->values()->all(),
            'attendance_total' => $allToday->count(),
            'filters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'project' => $filters['project'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'filter_options' => [
                'projects' => $projectOptions,
                'statuses' => array_map(fn (AttendanceStatus $s): string => $s->value, AttendanceStatus::cases()),
            ],
            'pending' => $this->pendingActions($companyId),
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * In-PHP filtering — today's set for one company is small (tens of rows),
     * so a query rebuild is not worth it and keeps the options/rows in step.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{search?: string, project?: int|null, status?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyAttendanceFilters(Collection $rows, array $filters): Collection
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $project = $filters['project'] ?? null;
        $status = $filters['status'] ?? null;

        return $rows->filter(function (array $r) use ($search, $project, $status): bool {
            if ($search !== '' && ! str_contains(mb_strtolower((string) ($r['employee'] ?? '')), $search)) {
                return false;
            }
            if ($project !== null && $r['project_id'] !== $project) {
                return false;
            }
            if ($status !== null && $r['status'] !== $status) {
                return false;
            }

            return true;
        });
    }

    /**
     * @return array<string, int|float>
     */
    private function kpis(int $companyId, string $today): array
    {
        $totalWorkers = Employee::query()->where('active', true)->count();

        $activeToday = Attendance::query()
            ->whereDate('date', $today)
            ->whereIn('status', self::WORKED)
            ->distinct('employee_id')
            ->count('employee_id');

        $onLeaveToday = Attendance::query()
            ->whereDate('date', $today)
            ->where('status', AttendanceStatus::Leave->value)
            ->distinct('employee_id')
            ->count('employee_id');

        $hoursToday = (float) Attendance::query()
            ->whereDate('date', $today)
            ->sum('hours_worked');

        return [
            'total_workers' => $totalWorkers,
            'active_today' => $activeToday,
            'on_leave_today' => $onLeaveToday,
            // Absent = active headcount not accounted for by presence or leave.
            'absent_today' => max(0, $totalWorkers - $activeToday - $onLeaveToday),
            'hours_today' => round($hoursToday, 2),
            'pending_calls' => $this->pendingCallsCount($companyId),
        ];
    }

    /**
     * Today's attendance rows, including workers deployed INTO this company
     * (whose home company is shown in its own column).
     *
     * @return list<array<string, mixed>>
     */
    private function attendanceToday(int $companyId, string $today): array
    {
        // Home-company lookup for anyone deployed into us right now.
        $homeByEmployee = EmployeeDeployment::query()
            ->where('host_company_id', $companyId)
            ->where('status', DeploymentStatus::Active->value)
            ->where('deployment_start', '<=', $today)
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $today))
            ->with('homeCompany:id,name')
            ->get()
            ->keyBy('employee_id')
            ->map(fn (EmployeeDeployment $d): ?string => $d->homeCompany?->name);

        return Attendance::query()
            ->whereDate('date', $today)
            ->with(['employee:id,full_name', 'project:id,name'])
            ->orderBy('employee_id')
            ->get()
            ->map(fn (Attendance $a): array => [
                'id' => $a->id,
                'employee' => $a->employee?->full_name,
                'home_company' => $homeByEmployee[$a->employee_id] ?? null,
                'project' => $a->project?->name,
                'project_id' => $a->project_id,
                'check_in' => $a->check_in,
                'check_out' => $a->check_out,
                'hours' => (float) $a->hours_worked,
                'status' => $a->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * The four pending-action lists.
     *
     * @return array<string, mixed>
     */
    private function pendingActions(int $companyId): array
    {
        return [
            'documents_expiring' => $this->documentsExpiringThisWeek(),
            'follow_up_calls' => $this->followUpCallsDue($companyId),
            'payroll_approvals' => $this->payrollApprovalsWaiting(),
            'advances_pending' => $this->advancesPending(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentsExpiringThisWeek(): array
    {
        $weekEnd = Carbon::now()->endOfWeek()->toDateString();

        return Document::query()
            ->where('is_current', true)
            ->where('is_exempt', false)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $weekEnd)
            ->orderBy('expiry_date')
            ->limit(15)
            ->get()
            ->map(fn (Document $d): array => [
                'id' => $d->id,
                'name' => $d->name ?? $d->type_key,
                'category' => $d->category,
                'expiry_date' => $d->expiry_date?->toDateString(),
            ])
            ->all();
    }

    /**
     * Calls whose follow-up date is today or overdue and still outstanding.
     * "Due today" reads the SOONEST outstanding follow-up per employee — a
     * newer call with no follow-up must not hide an older overdue one
     * (the Phase 7 call-panel rule).
     *
     * @return list<array<string, mixed>>
     */
    private function followUpCallsDue(int $companyId): array
    {
        $today = Carbon::now()->toDateString();

        return EmployeeCallLog::query()
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', $today)
            ->with('employee:id,full_name')
            ->orderBy('follow_up_date')
            ->get()
            ->unique('employee_id')
            ->map(fn (EmployeeCallLog $c): array => [
                'id' => $c->id,
                'employee' => $c->employee?->full_name,
                'follow_up_date' => $c->follow_up_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function pendingCallsCount(int $companyId): int
    {
        $today = Carbon::now()->toDateString();

        return EmployeeCallLog::query()
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', $today)
            ->distinct('employee_id')
            ->count('employee_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payrollApprovalsWaiting(): array
    {
        /** @var Collection<int, object{month: string, total: int}> $rows */
        $rows = Payroll::query()
            ->where('status', PayrollStatus::Pending->value)
            ->whereNull('approved_at')
            ->groupBy('month')
            ->orderByDesc('month')
            ->toBase()
            ->get(['month', DB::raw('COUNT(*) as total')]);

        return $rows
            ->map(fn (object $row): array => ['month' => (string) $row->month, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function advancesPending(): array
    {
        return Advance::query()
            ->where('status', AdvanceStatus::Pending->value)
            ->with('employee:id,full_name')
            ->latest('request_date')
            ->limit(15)
            ->get()
            ->map(fn (Advance $a): array => [
                'id' => $a->id,
                'employee' => $a->employee?->full_name,
                'amount' => (float) $a->getAttribute('amount'),
                'request_date' => $a->request_date->toDateString(),
            ])
            ->all();
    }
}
