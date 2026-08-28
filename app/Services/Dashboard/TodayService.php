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
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
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
     * @param  array{search?: string, project?: int|null, statuses?: list<string>, from?: string, to?: string}  $filters
     * @return array<string, mixed>
     */
    public function for(int $companyId, array $filters = []): array
    {
        $today = Carbon::now()->toDateString();
        $from = (string) ($filters['from'] ?? $today);
        $to = (string) ($filters['to'] ?? $from);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        // Fetch the range's attendance once, unfiltered — the project dropdown is
        // built from this stable set, then the visible rows are the filtered
        // subset. KPIs are the range's headline totals (never text-filtered).
        $allRows = collect($this->attendanceInRange($companyId, $from, $to));

        $projectOptions = $allRows
            ->filter(fn (array $r): bool => $r['project_id'] !== null)
            ->unique('project_id')
            ->map(fn (array $r): array => ['id' => $r['project_id'], 'name' => $r['project']])
            ->sortBy('name')
            ->values()
            ->all();

        $rows = $this->applyAttendanceFilters($allRows, $filters);

        return [
            'kpis' => $this->kpis($companyId, $from, $to, $today, $allRows),
            'project_breakdown' => $this->projectBreakdown($allRows),
            'projects_no_activity' => ($from <= $today && $today <= $to)
                ? $this->projectsWithNoActivity($today)
                : [],
            'attendance' => $rows->values()->all(),
            'attendance_total' => $allRows->count(),
            'single_day' => $from === $to,
            'filters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'project' => $filters['project'] ?? null,
                'statuses' => $filters['statuses'] ?? [],
                'from' => $from,
                'to' => $to,
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
     * @param  array{search?: string, project?: int|null, statuses?: list<string>}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyAttendanceFilters(Collection $rows, array $filters): Collection
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $project = $filters['project'] ?? null;
        $statuses = $filters['statuses'] ?? [];

        return $rows->filter(function (array $r) use ($search, $project, $statuses): bool {
            if ($search !== '' && ! str_contains(mb_strtolower((string) ($r['employee'] ?? '')), $search)) {
                return false;
            }
            if ($project !== null && $r['project_id'] !== $project) {
                return false;
            }
            // Empty = all statuses; otherwise keep only the ticked ones.
            if ($statuses !== [] && ! in_array($r['status'], $statuses, true)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Headline figures for the selected range. For a single day, "absent" is the
     * active headcount not accounted for by presence/leave; for a multi-day
     * range that is meaningless, so it counts distinct employees with a recorded
     * absence in the window.
     *
     * @param  Collection<int, array<string, mixed>>  $allRows
     * @return array<string, int|float>
     */
    private function kpis(int $companyId, string $from, string $to, string $today, Collection $allRows): array
    {
        $totalWorkers = Employee::query()->where('active', true)->count();

        $present = Attendance::query()
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', self::WORKED)
            ->distinct('employee_id')
            ->count('employee_id');

        $onLeave = Attendance::query()
            ->whereBetween('date', [$from, $to])
            ->where('status', AttendanceStatus::Leave->value)
            ->distinct('employee_id')
            ->count('employee_id');

        // Sum REAL clock hours from the mapped rows (Attendance::displayHours),
        // so the KPI agrees with the per-row and project-breakdown figures and
        // isn't thrown off by clerk-entered full days left at hours_worked=0.
        $hours = (float) $allRows->sum(fn (array $r): float => (float) $r['hours']);

        // Currently checked in only makes sense for today (open PWA punch).
        $checkedInNow = ($from <= $today && $today <= $to)
            ? Attendance::query()->whereDate('date', $today)
                ->whereNotNull('check_in_at')->whereNull('check_out_at')
                ->distinct('employee_id')->count('employee_id')
            : 0;

        $absent = $from === $to
            ? max(0, $totalWorkers - $present - $onLeave)
            : Attendance::query()->whereBetween('date', [$from, $to])
                ->where('status', AttendanceStatus::Absent->value)
                ->distinct('employee_id')->count('employee_id');

        return [
            'total_workers' => $totalWorkers,
            'active_today' => $present,
            'on_leave_today' => $onLeave,
            'absent_today' => $absent,
            'checked_in_now' => $checkedInNow,
            'hours_today' => round($hours, 2),
            'pending_calls' => $this->pendingCallsCount($companyId),
        ];
    }

    /**
     * Attendance rows in [from, to], including workers deployed INTO this company
     * (whose home company is shown in its own column). Each row carries its date
     * so a multi-day range reads as a log.
     *
     * @return list<array<string, mixed>>
     */
    private function attendanceInRange(int $companyId, string $from, string $to): array
    {
        // Home-company lookup for anyone deployed into us within the window.
        $homeByEmployee = EmployeeDeployment::query()
            ->where('host_company_id', $companyId)
            ->where('status', DeploymentStatus::Active->value)
            ->where('deployment_start', '<=', $to)
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $from))
            ->with('homeCompany:id,name')
            ->get()
            ->keyBy('employee_id')
            ->map(fn (EmployeeDeployment $d): ?string => $d->homeCompany?->name);

        return Attendance::query()
            ->whereBetween('date', [$from, $to])
            ->with(['employee:id,full_name', 'project:id,name'])
            ->orderByDesc('date')->orderBy('employee_id')
            ->get()
            ->map(fn (Attendance $a): array => [
                'id' => $a->id,
                'employee' => $a->employee?->full_name,
                'home_company' => $homeByEmployee[$a->employee_id] ?? null,
                'project' => $a->project?->name,
                'project_id' => $a->project_id,
                'date' => $a->date->toDateString(),
                'check_in' => $a->check_in,
                'check_out' => $a->check_out,
                // REAL clock hours (see Attendance::displayHours) — but ONLY for a
                // WORKED status. An absent/leave row must never show hours, even
                // if it carries stale check_in/out times from a since-changed
                // present state (that also kept them out of the hours KPI).
                'hours' => in_array($a->status->value, self::WORKED, true) ? $a->displayHours() : 0.0,
                'worked' => in_array($a->status->value, self::WORKED, true),
                'still_working' => in_array($a->status->value, self::WORKED, true) && $a->isOpenShift(),
                'status' => $a->status->value,
                // GPS distance from the project site (metres), when captured.
                'distance' => $a->distance_from_project !== null ? (float) $a->distance_from_project : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Per-project roll-up for today: assigned roster, present, absent, hours.
     * Grouped from today's attendance; "assigned" reads the project's rate
     * roster (falling back to the number who actually showed up).
     *
     * @param  Collection<int, array<string, mixed>>  $allToday
     * @return list<array<string, mixed>>
     */
    private function projectBreakdown(Collection $allToday): array
    {
        $byProject = $allToday->groupBy(fn (array $r): string => (string) ($r['project_id'] ?? '0'));

        $projectIds = $byProject->keys()->filter(fn (string $k): bool => $k !== '0')->map(fn (string $k): int => (int) $k)->all();
        $assigned = ProjectEmployeeRate::query()
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, COUNT(DISTINCT employee_id) as c')
            ->groupBy('project_id')
            ->pluck('c', 'project_id');

        return $byProject->map(function (Collection $rows, string $pidKey) use ($assigned): array {
            $pid = $pidKey === '0' ? null : (int) $pidKey;
            $present = $rows->filter(fn (array $r): bool => in_array($r['status'], self::WORKED, true))->count();
            $rosterCount = $pid !== null ? (int) ($assigned[$pid] ?? 0) : 0;
            $assignedCount = max($rosterCount, $rows->count());

            return [
                'project_id' => $pid,
                'project' => $pid !== null ? ($rows->first()['project'] ?? null) : null,
                'assigned' => $assignedCount,
                'present' => $present,
                'absent' => max(0, $assignedCount - $present),
                'hours' => round($rows->sum(fn (array $r): float => (float) $r['hours']), 2),
            ];
        })->values()->sortByDesc('present')->values()->all();
    }

    /**
     * ACTIVE, STAFFED projects with nobody working today — the admin's "who's
     * quiet?" list. A project qualifies when it is active/in-progress, HAS at
     * least one assigned worker (project_employee_rates), and has ZERO worked
     * attendance today. Projects with no assigned workers are a staffing gap, a
     * different problem, and are excluded. `last_activity` is the most recent
     * worked day BEFORE today (null → "Never"). Most-stale first.
     *
     * @return list<array<string, mixed>>
     */
    private function projectsWithNoActivity(string $today): array
    {
        // Active + in-progress projects of the acting company (scopeActive).
        $projects = Project::query()->active()->get(['id', 'name']);
        if ($projects->isEmpty()) {
            return [];
        }
        $projectIds = $projects->pluck('id')->all();

        // Assigned worker count per project (the roster).
        $assigned = ProjectEmployeeRate::query()
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, COUNT(DISTINCT employee_id) as c')
            ->groupBy('project_id')
            ->pluck('c', 'project_id');

        // Projects that DO have someone working today → excluded.
        $activeToday = Attendance::query()
            ->whereDate('date', $today)
            ->whereIn('status', self::WORKED)
            ->whereIn('project_id', $projectIds)
            ->distinct()
            ->pluck('project_id')
            ->all();

        // Most recent worked day BEFORE today, per project.
        $lastActivity = Attendance::query()
            ->whereIn('status', self::WORKED)
            ->whereIn('project_id', $projectIds)
            ->whereDate('date', '<', $today)
            ->selectRaw('project_id, MAX(date) as last')
            ->groupBy('project_id')
            ->pluck('last', 'project_id');

        $out = [];
        foreach ($projects as $project) {
            $assignedCount = (int) ($assigned[$project->id] ?? 0);
            if ($assignedCount === 0) {
                continue; // unstaffed — a staffing issue, not "no activity"
            }
            if (in_array($project->id, $activeToday, true)) {
                continue; // someone is working today
            }

            $last = $lastActivity[$project->id] ?? null;
            $last = $last !== null ? Carbon::parse((string) $last)->toDateString() : null;

            $out[] = [
                'project_id' => $project->id,
                'project' => $project->name,
                'assigned' => $assignedCount,
                'last_activity' => $last,
                'days_ago' => $last !== null ? Carbon::parse($last)->diffInDays(Carbon::parse($today)) : null,
            ];
        }

        // Most concerning first: "Never" (null) at the top, then the oldest.
        usort($out, fn (array $a, array $b): int => ($a['last_activity'] ?? '') <=> ($b['last_activity'] ?? ''));

        return $out;
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
