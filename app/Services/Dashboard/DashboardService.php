<?php

namespace App\Services\Dashboard;

use App\Enums\AttendanceStatus;
use App\Enums\DeploymentStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Services\Documents\DocumentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Screen 03 — the dashboard's numbers, all for ONE company.
 *
 * Everything is read through the tenant-scoped models, so a figure can only
 * ever reflect the company the caller resolved — there is no company_id
 * threaded through by hand. The one exception is deployments (they span two
 * companies and carry no global scope), which are filtered explicitly.
 *
 * The whole payload is cached for a short window per company: a dashboard is
 * refreshed constantly and none of these figures needs to be to-the-second.
 * The cache key carries the company id so one company's numbers can never be
 * served to another.
 */
class DashboardService
{
    private const CACHE_TTL_SECONDS = 120;

    public function __construct(private readonly DocumentStatus $documentStatus) {}

    /**
     * @return array<string, mixed>
     */
    public function for(int $companyId): array
    {
        return Cache::remember("dashboard:{$companyId}", self::CACHE_TTL_SECONDS, fn (): array => [
            'kpis' => $this->kpis($companyId),
            'charts' => $this->charts(),
            'expiring' => $this->expiringDocuments(),
            'activity' => $this->recentActivity($companyId),
        ]);
    }

    /**
     * Drop the cached payload — call after a write that the dashboard shows,
     * or let the short TTL age it out.
     */
    public function forget(int $companyId): void
    {
        Cache::forget("dashboard:{$companyId}");
    }

    /**
     * @return array<string, int|float>
     */
    private function kpis(int $companyId): array
    {
        $now = Carbon::now();
        [$monthStart, $monthEnd] = [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()];
        $today = $now->toDateString();

        return [
            // Row 1
            'active_employees' => Employee::query()->where('active', true)->count(),
            'active_projects' => Project::query()
                ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::InProgress->value])
                ->count(),
            // Pending invoices: money still owed to us on unpaid/partial sales
            'pending_invoices_eur' => (float) Invoice::query()
                ->where('type', InvoiceType::Sale->value)
                ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Partial->value, PaymentStatus::Pending->value])
                ->sum('total'),
            'documents_expiring' => $this->expiringCount(),

            // Row 2
            'attendance_present_today' => Attendance::query()
                ->whereDate('date', $today)
                ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::EarlyLeave->value])
                ->distinct('employee_id')
                ->count('employee_id'),
            'attendance_total_today' => Employee::query()->where('active', true)->count(),
            'expenses_this_month_eur' => (float) Expense::query()
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->sum('total'),
            'billing_this_month_eur' => (float) Invoice::query()
                ->where('type', InvoiceType::Sale->value)
                ->whereBetween('invoice_date', [$monthStart, $monthEnd])
                ->sum('total'),
            'deployed_employees' => $this->deployedOut($companyId),
        ];
    }

    /**
     * Employees of THIS company currently posted to another (Option A or not).
     * Deployments carry no tenant scope, so the home company is matched by hand.
     */
    private function deployedOut(int $companyId): int
    {
        $today = Carbon::now()->toDateString();

        return EmployeeDeployment::query()
            ->where('home_company_id', $companyId)
            ->where('status', DeploymentStatus::Active->value)
            ->where('deployment_start', '<=', $today)
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $today))
            ->distinct('employee_id')
            ->count('employee_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function charts(): array
    {
        return [
            'revenue_vs_expenses' => $this->revenueVsExpenses(),
            'project_status' => $this->projectStatusBreakdown(),
            'attendance_trend' => $this->attendanceTrend(),
        ];
    }

    /**
     * Sales-invoice billing vs expenses, month by month for the last 6 months
     * (oldest first, so the chart reads left-to-right in time).
     *
     * @return array{labels: list<string>, revenue: list<float>, expenses: list<float>}
     */
    private function revenueVsExpenses(): array
    {
        $labels = [];
        $revenue = [];
        $expenses = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->startOfMonth()->subMonths($i);
            [$start, $end] = [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()];

            $labels[] = $month->format('Y-m');
            $revenue[] = (float) Invoice::query()
                ->where('type', InvoiceType::Sale->value)
                ->whereBetween('invoice_date', [$start, $end])
                ->sum('total');
            $expenses[] = (float) Expense::query()
                ->whereBetween('date', [$start, $end])
                ->sum('total');
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'expenses' => $expenses];
    }

    /**
     * @return array<string, int>
     */
    private function projectStatusBreakdown(): array
    {
        $counts = Project::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Every status present, in a fixed order, so the donut is stable.
        $out = [];

        foreach (ProjectStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    /**
     * Distinct present workers per day for the last 30 days (oldest first).
     *
     * @return array{labels: list<string>, present: list<int>}
     */
    private function attendanceTrend(): array
    {
        $start = Carbon::now()->subDays(29)->startOfDay();

        $rows = Attendance::query()
            ->selectRaw('date, COUNT(DISTINCT employee_id) as present')
            ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::EarlyLeave->value])
            ->whereBetween('date', [$start->toDateString(), Carbon::now()->toDateString()])
            ->groupBy('date')
            ->pluck('present', 'date');

        $labels = [];
        $present = [];

        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $labels[] = $day;
            $present[] = (int) ($rows[$day] ?? 0);
        }

        return ['labels' => $labels, 'present' => $present];
    }

    /**
     * Current documents inside the warn window or already expired, worst first,
     * for the bottom-left panel.
     *
     * @return list<array<string, mixed>>
     */
    private function expiringDocuments(int $limit = 8): array
    {
        $horizon = Carbon::now()->addDays($this->documentStatus->warnDays())->toDateString();

        return Document::query()
            ->where('is_current', true)
            ->where('is_exempt', false)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $horizon)
            ->orderBy('expiry_date')
            ->limit($limit)
            ->get()
            ->map(function (Document $doc): array {
                [$status, $days] = $this->documentStatus->of($doc);

                return [
                    'id' => $doc->id,
                    'name' => $doc->name ?? $doc->type_key,
                    'category' => $doc->category,
                    'status' => $status,
                    'days' => $days,
                    'expiry_date' => $doc->expiry_date?->toDateString(),
                ];
            })
            ->all();
    }

    private function expiringCount(): int
    {
        $horizon = Carbon::now()->addDays($this->documentStatus->warnDays())->toDateString();

        return Document::query()
            ->where('is_current', true)
            ->where('is_exempt', false)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $horizon)
            ->count();
    }

    /**
     * Last 10 audit entries for this company — the activity feed. Scoped by
     * company_id explicitly because AuditLog is not a tenant-scoped model.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $companyId): array
    {
        return AuditLog::query()
            ->where('company_id', $companyId)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'module' => $log->module,
                'entity_name' => $log->entity_name,
                'user_name' => $log->user_name,
                'created_at' => $log->created_at->toDateTimeString(),
            ])
            ->all();
    }
}
