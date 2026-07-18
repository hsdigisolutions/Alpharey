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
        'documents', 'projects', 'commission', 'timesheet', 'deployments',
    ];

    public function __construct(private readonly DocumentStatus $documentStatus) {}

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function for(string $module, array $filters): array
    {
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
        $rows = Attendance::query()->whereBetween('date', [$from, $to]);

        return [
            'figures' => [
                'total_records' => (clone $rows)->count(),
                'total_hours' => round((float) (clone $rows)->sum('hours_worked'), 2),
                'overtime_hours' => round((float) (clone $rows)->sum('overtime_hours'), 2),
                'absences' => (clone $rows)->where('status', 'absent')->count(),
            ],
            'by_employee' => Attendance::query()
                ->whereBetween('date', [$from, $to])
                ->selectRaw('employee_id, COUNT(*) as days, SUM(hours_worked) as hours')
                ->groupBy('employee_id')
                ->with('employee:id,full_name')
                ->get()
                ->map(fn (Attendance $a): array => [
                    'employee' => $a->employee?->full_name,
                    'days' => (int) $a->getAttribute('days'),
                    'hours' => round((float) $a->getAttribute('hours'), 2),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payroll(string $from, string $to): array
    {
        $months = $this->monthsInRange($from, $to);

        $payrolls = Payroll::query()->whereIn('month', $months)->get();

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
            'expense_by_category' => Expense::query()
                ->whereBetween('date', [$from, $to])
                ->with('category:id,name')
                ->get()
                ->groupBy(fn (Expense $e): string => $e->category !== null ? $e->category->name : '—')
                ->map(fn ($group): float => round((float) $group->sum(fn (Expense $e): float => (float) $e->total), 2))
                ->all(),
        ];
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
                ->selectRaw('project_id, SUM(hours_worked) as hours')
                ->groupBy('project_id')
                ->with('project:id,name')
                ->get()
                ->map(fn (Attendance $a): array => [
                    'project' => $a->project?->name,
                    'hours' => round((float) $a->getAttribute('hours'), 2),
                ])
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
        return [
            'figures' => [
                'total_hours' => round((float) Attendance::query()->whereBetween('date', [$from, $to])->sum('hours_worked'), 2),
            ],
            'rows' => Attendance::query()
                ->whereBetween('date', [$from, $to])
                ->selectRaw('employee_id, project_id, SUM(hours_worked) as hours')
                ->groupBy('employee_id', 'project_id')
                ->with(['employee:id,full_name', 'project:id,name'])
                ->get()
                ->map(fn (Attendance $a): array => [
                    'employee' => $a->employee?->full_name,
                    'project' => $a->project !== null ? $a->project->name : '—',
                    'hours' => round((float) $a->getAttribute('hours'), 2),
                ])
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
