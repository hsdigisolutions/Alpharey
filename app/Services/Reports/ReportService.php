<?php

namespace App\Services\Reports;

use App\Enums\CommissionStatus;
use App\Enums\DeploymentStatus;
use App\Enums\InvoiceType;
use App\Enums\LeaveStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\CommissionReportEntry;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Leave;
use App\Models\Payroll;
use App\Models\Project;
use App\Services\Attendance\AttendanceService;
use App\Services\Documents\DocumentStatus;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;

/**
 * Screen 14 — Reports. One report module at a time, each producing the figures
 * (and where the spec asks, a table) for the active company inside a date
 * range.
 *
 * Everything reads through the tenant-scoped models, so a report can only ever
 * reflect the company the controller resolved. Payroll and commission figures
 * are money the caller is allowed to see (the controller gates the whole
 * module behind reports.view AND the relevant module's view — see the
 * controller); the numbers here are aggregates over the company's own books,
 * which are not encrypted (only per-employee pay is — scaffolding decision 28).
 */
class ReportService
{
    /** The report modules this service can produce. */
    public const MODULES = [
        'employees', 'attendance', 'payroll', 'financial',
        'documents', 'projects', 'profitability', 'commission', 'timesheet', 'deployments',
    ];

    public function __construct(
        private readonly DocumentStatus $documentStatus,
        private readonly ProfitabilityService $profitability,
        private readonly CurrentCompany $currentCompany,
    ) {}

    private ?int $breakMinutesMemo = null;

    /**
     * Columns displayHoursNet() needs, so the report rows can be summed as NET
     * worked hours in PHP (a full 08:00–17:00 day reads 8 h) — SQL SUM() can't
     * call the helper. Grouping/eager-load keys (employee_id, project_id) are
     * added per query.
     *
     * @var list<string>
     */
    private const HOURS_COLUMNS = [
        'id', 'date', 'status', 'day_type', 'wage_type_snapshot',
        'check_in', 'check_out', 'check_in_at', 'hours_worked',
    ];

    /** Standard unpaid break for the report's company, resolved once. */
    private function breakMinutes(): int
    {
        return $this->breakMinutesMemo ??= app(AttendanceService::class)
            ->breakDurationMinutes($this->currentCompany->id() ?? 0);
    }

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function for(string $module, array $filters): array
    {
        // Profitability keeps the raw filters (project/client + an OPTIONAL,
        // possibly-null range meaning "all time") rather than the year-to-date
        // default the other modules assume.
        if ($module === 'profitability') {
            return $this->profitability->report($this->currentCompany->id() ?? 0, $filters);
        }

        [$from, $to] = $this->range($filters);

        return match ($module) {
            'attendance' => $this->attendance($from, $to),
            'payroll' => $this->payroll($from, $to),
            'financial' => $this->financial($from, $to),
            'documents' => $this->documents(),
            'projects' => $this->projects(),
            'commission' => $this->commission($from, $to),
            'timesheet' => $this->timesheet($from, $to),
            'deployments' => $this->deployments($from, $to),
            default => $this->employees(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function employees(): array
    {
        $byWage = [];
        foreach (WageType::cases() as $type) {
            $byWage[$type->value] = Employee::query()->where('wage_type', $type->value)->count();
        }

        $onLeave = Leave::query()
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('start_date', '<=', Carbon::now()->toDateString())
            ->whereDate('end_date', '>=', Carbon::now()->toDateString())
            ->distinct('employee_id')
            ->count('employee_id');

        return [
            'figures' => [
                'total' => Employee::query()->count(),
                'active' => Employee::query()->where('active', true)->count(),
                'inactive' => Employee::query()->where('active', false)->count(),
                'on_leave' => $onLeave,
            ],
            'by_wage_type' => $byWage,
            'by_designation' => Employee::query()
                ->selectRaw('designation, COUNT(*) as total')
                ->groupBy('designation')
                ->pluck('total', 'designation')
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendance(string $from, string $to): array
    {
        $break = $this->breakMinutes();
        $rows = Attendance::query()->whereBetween('date', [$from, $to])
            ->with('employee:id,full_name')
            ->get([...self::HOURS_COLUMNS, 'employee_id', 'overtime_hours']);

        return [
            'figures' => [
                'total_records' => $rows->count(),
                // NET worked hours in PHP (displayHoursNet reads 0 for a
                // non-worked row, so summing over every row is correct).
                'total_hours' => round((float) $rows->sum(fn (Attendance $a): float => $a->displayHoursNet($break)), 2),
                'overtime_hours' => round((float) $rows->sum(fn (Attendance $a): float => (float) $a->overtime_hours), 2),
                'absences' => $rows->filter(fn (Attendance $a): bool => $a->status->value === 'absent')->count(),
            ],
            'by_employee' => $rows->groupBy('employee_id')
                ->map(fn ($group): array => [
                    'employee' => $group->first()?->employee?->full_name,
                    'days' => $group->count(),
                    'hours' => round((float) $group->sum(fn (Attendance $a): float => $a->displayHoursNet($break)), 2),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payroll(string $from, string $to): array
    {
        $months = $this->monthsInRange($from, $to);

        // Eager-load employee — by_employee below reads $group->first()->employee
        // per group, which lazy-loaded one query per employee (N+1).
        $payrolls = Payroll::query()->with('employee:id,full_name')->whereIn('month', $months)->get();

        $nets = $payrolls->map(fn (Payroll $p): float => (float) $p->getAttribute('net_amount'));

        return [
            'figures' => [
                'total' => round((float) $nets->sum(), 2),
                'average' => $nets->isEmpty() ? 0.0 : round((float) $nets->avg(), 2),
                'highest' => round((float) ($nets->max() ?? 0), 2),
                'lowest' => round((float) ($nets->min() ?? 0), 2),
                'advance_deductions' => round((float) $payrolls->sum(fn (Payroll $p): float => (float) $p->getAttribute('advance_deductions')), 2),
            ],
            'by_employee' => $payrolls
                ->groupBy('employee_id')
                ->map(fn ($group) => [
                    'employee' => $group->first()->employee?->full_name,
                    'net' => round((float) $group->sum(fn (Payroll $p): float => (float) $p->getAttribute('net_amount')), 2),
                ])
                ->values()
                ->all(),
            'by_month' => collect($months)->map(fn (string $m): array => [
                'month' => $m,
                'net' => round((float) $payrolls->where('month', $m)->sum(fn (Payroll $p): float => (float) $p->getAttribute('net_amount')), 2),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financial(string $from, string $to): array
    {
        $sales = (float) Invoice::query()->where('type', InvoiceType::Sale->value)
            ->whereBetween('invoice_date', [$from, $to])->sum('total');
        $expenseInvoices = (float) Invoice::query()->where('type', InvoiceType::Expense->value)
            ->whereBetween('invoice_date', [$from, $to])->sum('total');
        $expenses = (float) Expense::query()->whereBetween('date', [$from, $to])->sum('total');

        return [
            'figures' => [
                'sales_total' => round($sales, 2),
                'expense_total' => round($expenses + $expenseInvoices, 2),
                'net_position' => round($sales - $expenses - $expenseInvoices, 2),
            ],
            'unpaid_invoices' => Invoice::query()
                ->where('type', InvoiceType::Sale->value)
                ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Partial->value])
                ->orderByDesc('invoice_date')
                ->limit(50)
                ->get()
                ->map(fn (Invoice $i): array => [
                    'number' => $i->number,
                    'total' => round((float) $i->total, 2),
                    'date' => $i->invoice_date->toDateString(),
                ])
                ->all(),
            // Split-aware: a multi-category expense distributes its total across
            // its split rows; a single-category one attributes the whole total
            // to its one category (categoryBreakdown() is the shared authority).
            'expense_by_category' => $this->expensesByCategory($from, $to),
        ];
    }

    /**
     * Expense totals per category over the window, split-aware.
     *
     * @return array<string, float>
     */
    private function expensesByCategory(string $from, string $to): array
    {
        $byCategory = [];

        Expense::query()
            ->whereBetween('date', [$from, $to])
            ->with(['category:id,name', 'splits.category:id,name'])
            ->get()
            ->each(function (Expense $e) use (&$byCategory): void {
                $breakdown = $e->categoryBreakdown();

                if ($breakdown === null) {
                    $byCategory['—'] = ($byCategory['—'] ?? 0.0) + (float) $e->total;

                    return;
                }

                foreach ($breakdown as $slice) {
                    $name = $slice['category'] ?? '—';
                    $byCategory[$name] = ($byCategory[$name] ?? 0.0) + $slice['amount'];
                }
            });

        return array_map(fn (float $v): float => round($v, 2), $byCategory);
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(): array
    {
        $today = Carbon::now();
        $current = Document::query()->where('is_current', true)->where('is_exempt', false)->whereNotNull('expiry_date');

        $countWithin = fn (int $days): int => (clone $current)
            ->whereDate('expiry_date', '>=', $today->toDateString())
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($days)->toDateString())
            ->count();

        return [
            'figures' => [
                'expired' => (clone $current)->whereDate('expiry_date', '<', $today->toDateString())->count(),
                'expiring_30' => $countWithin(30),
                'expiring_60' => $countWithin(60),
                'expiring_90' => $countWithin(90),
                'valid' => (clone $current)->whereDate('expiry_date', '>', $today->copy()->addDays($this->documentStatus->warnDays())->toDateString())->count(),
            ],
            'by_type' => Document::query()
                ->where('is_current', true)
                ->selectRaw('type_key, COUNT(*) as total')
                ->groupBy('type_key')
                ->pluck('total', 'type_key')
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projects(): array
    {
        $break = $this->breakMinutes();
        $byStatus = [];
        foreach (ProjectStatus::cases() as $status) {
            $byStatus[$status->value] = Project::query()->where('status', $status->value)->count();
        }

        return [
            'figures' => [
                'active' => $byStatus[ProjectStatus::Active->value] + $byStatus[ProjectStatus::InProgress->value],
                'completed' => $byStatus[ProjectStatus::Completed->value],
                'cancelled' => $byStatus[ProjectStatus::Cancelled->value],
                'total' => Project::query()->count(),
            ],
            'by_status' => $byStatus,
            'hours_per_project' => Attendance::query()
                ->whereNotNull('project_id')
                ->with('project:id,name')
                ->get([...self::HOURS_COLUMNS, 'project_id'])
                ->groupBy('project_id')
                ->map(fn ($group): array => [
                    'project' => $group->first()?->project?->name,
                    // NET worked hours in PHP.
                    'hours' => round((float) $group->sum(fn (Attendance $a): float => $a->displayHoursNet($break)), 2),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commission(string $from, string $to): array
    {
        $months = $this->monthsInRange($from, $to);
        $entries = CommissionReportEntry::query()->whereIn('month', $months)
            ->with(['employee:id,full_name', 'project:id,name'])->get();

        return [
            'figures' => [
                'total' => round((float) $entries->sum(fn (CommissionReportEntry $c): float => (float) ($c->adjusted_amount ?? $c->original_amount)), 2),
                'finalized' => $entries->where('status', CommissionStatus::Finalized->value)->count(),
                'paid' => $entries->whereNotNull('paid_at')->count(),
                'entries' => $entries->count(),
            ],
            'rows' => $entries->map(fn (CommissionReportEntry $c): array => [
                'employee' => $c->employee?->full_name,
                'project' => $c->project?->name,
                'percent' => $c->commission_percent !== null ? (float) $c->commission_percent : null,
                'original' => round((float) $c->original_amount, 2),
                'adjusted' => $c->adjusted_amount !== null ? round((float) $c->adjusted_amount, 2) : null,
                'status' => $c->status->value,
                'paid' => $c->paid_at !== null,
            ])->all(),
        ];
    }

    /**
     * Hours per employee per project across the range.
     *
     * @return array<string, mixed>
     */
    private function timesheet(string $from, string $to): array
    {
        $break = $this->breakMinutes();
        $rows = Attendance::query()
            ->whereBetween('date', [$from, $to])
            ->with(['employee:id,full_name', 'project:id,name'])
            ->get([...self::HOURS_COLUMNS, 'employee_id', 'project_id']);

        return [
            'figures' => [
                // NET worked hours in PHP.
                'total_hours' => round((float) $rows->sum(fn (Attendance $a): float => $a->displayHoursNet($break)), 2),
            ],
            'rows' => $rows
                ->groupBy(fn (Attendance $a): string => $a->employee_id.'|'.($a->project_id ?? '0'))
                ->map(function ($group) use ($break): array {
                    $first = $group->first();
                    $project = $first?->project;

                    return [
                        'employee' => $first?->employee?->full_name,
                        'project' => $project !== null ? $project->name : '—',
                        'hours' => round((float) $group->sum(fn (Attendance $a): float => $a->displayHoursNet($break)), 2),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Cross-company deployments touching the range (home OR host = us).
     *
     * @return array<string, mixed>
     */
    private function deployments(string $from, string $to): array
    {
        $companyId = app(CurrentCompany::class)->id();

        $deployments = EmployeeDeployment::query()
            ->where(fn ($q) => $q->where('home_company_id', $companyId)->orWhere('host_company_id', $companyId))
            ->where('deployment_start', '<=', $to)
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $from))
            ->with(['employee:id,full_name', 'homeCompany:id,name', 'hostCompany:id,name', 'project:id,name'])
            ->get();

        return [
            'figures' => [
                'total' => $deployments->count(),
                'active' => $deployments->where('status', DeploymentStatus::Active->value)->count(),
                'completed' => $deployments->where('status', DeploymentStatus::Completed->value)->count(),
            ],
            'rows' => $deployments->map(fn (EmployeeDeployment $d): array => [
                'employee' => $d->employee?->full_name,
                'from' => $d->homeCompany?->name,
                'to' => $d->hostCompany?->name,
                'project' => $d->project?->name,
                'start' => $d->deployment_start->toDateString(),
                'end' => $d->deployment_end?->toDateString(),
                'billing_method' => $d->billing_method->value,
                'status' => $d->status->value,
            ])->all(),
        ];
    }

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{0: string, 1: string}
     */
    private function range(array $filters): array
    {
        $from = ! empty($filters['from'])
            ? Carbon::parse($filters['from'])->toDateString()
            : Carbon::now()->startOfYear()->toDateString();

        $to = ! empty($filters['to'])
            ? Carbon::parse($filters['to'])->toDateString()
            : Carbon::now()->toDateString();

        return [$from, $to];
    }

    /**
     * The 'YYYY-MM' keys inside a date range (payroll/commission are keyed by month).
     *
     * @return list<string>
     */
    private function monthsInRange(string $from, string $to): array
    {
        $cursor = Carbon::parse($from)->startOfMonth();
        $last = Carbon::parse($to)->startOfMonth();
        $months = [];

        while ($cursor->lte($last)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $months;
    }
}
