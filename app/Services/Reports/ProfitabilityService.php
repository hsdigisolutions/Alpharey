<?php

namespace App\Services\Reports;

use App\Enums\BillingType;
use App\Enums\ProjectRateType;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\Scopes\CompanyScope;
use App\Models\SubcontractorPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Project profitability (P&L). Revenue − cost per project, all server-side and
 * company-scoped.
 *
 *   Revenue = client_hour_rate × hours          (billing = hourly)
 *           = client_meter_rate × metres        (billing = per_meter)
 *           = Σ paid SALE invoices              (billing = fixed / milestone)
 *   Cost    = Σ attendance.total_amount (labour) + Σ approved project expenses
 *           + Σ paid subcontractor payments
 *   Profit  = Revenue − Cost
 *   Margin  = Profit / Revenue × 100
 *
 * An OUTSOURCED project replaces our own labour with its flat `outsource_cost`:
 *   Profit = Revenue − outsource_cost − expenses − subcontractor payments.
 *
 * The whole result is cached per company behind a signature built from the
 * MAX(updated_at) of attendance / expenses / projects / subcontractor payments,
 * so a new punch or expense mints a fresh key and the figures recompute — no
 * observers, no stale P&L.
 */
class ProfitabilityService
{
    private const CACHE_TTL_SECONDS = 600;

    /** Margin thresholds (percent): > green ≥ amber, below = red. */
    private const MARGIN_GREEN = 15.0;

    private const MARGIN_AMBER = 5.0;

    /**
     * The Reports-screen entry: a company-wide table, or one project's detail
     * (with day + month breakdowns) when a project is selected.
     *
     * @param  array{from?: string|null, to?: string|null, project_id?: int|string|null, client_id?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function report(int $companyId, array $filters): array
    {
        $from = ($filters['from'] ?? null) ?: null;
        $to = ($filters['to'] ?? null) ?: null;
        $projectId = ($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null;
        $clientId = ($filters['client_id'] ?? null) ? (int) $filters['client_id'] : null;

        $key = 'profit:report:'.$companyId.':'.md5(implode('|', [
            $from ?? '', $to ?? '', $projectId ?? '', $clientId ?? '', $this->signature($companyId),
        ]));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($companyId, $from, $to, $projectId, $clientId): array {
            if ($projectId !== null) {
                $project = Project::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $companyId)
                    ->find($projectId);

                if ($project === null) {
                    return ['figures' => $this->emptyFigures(), 'rows' => [], 'selected' => null];
                }

                $detail = $this->forProject($project, $from, $to, true);

                return [
                    'figures' => $this->figuresFor([$detail]),
                    'rows' => [$this->rowFrom($detail)],
                    'selected' => $detail,
                ];
            }

            $rows = $this->companyRows($companyId, $from, $to, $clientId);

            return [
                'figures' => $this->figuresFor($rows),
                'rows' => array_map(fn (array $d): array => $this->rowFrom($d), $rows),
                'selected' => null,
            ];
        });
    }

    /**
     * One project's full P&L (summary + day + month breakdowns). Used by the
     * project Resumen card (breakdown off) and the report drill-down (on).
     *
     * @return array<string, mixed>
     */
    public function forProject(Project $project, ?string $from = null, ?string $to = null, bool $withBreakdown = false): array
    {
        $agg = $this->attendanceAggregate($project->company_id, $from, $to, $project->id);
        $hours = $agg['hours'];
        $meters = $agg['meters'];
        $labourFromAttendance = $agg['labour'];

        $expenses = $this->expenseTotal($project->company_id, $from, $to, $project->id);
        $subcontract = $this->subcontractorTotal($project->company_id, $from, $to, $project->id);

        $revenue = $this->revenueFor($project, $hours, $meters, $from, $to);

        // Outsourced projects cost their flat outsource fee instead of our own
        // crew's wages; a non-outsourced project's labour is the attendance sum.
        $outsourced = (bool) $project->outsourced;
        $labourCost = $outsourced ? (float) ($project->outsource_cost ?? 0.0) : $labourFromAttendance;

        $cost = round($labourCost + $expenses + $subcontract, 2);
        $revenue = round($revenue, 2);
        $profit = round($revenue - $cost, 2);

        [$margin, $health] = $this->classify($revenue, $cost, $profit);

        $clientHourRate = $project->client_hour_rate !== null ? (float) $project->client_hour_rate : null;
        $avgCostPerHour = $hours > 0 ? round($labourCost / $hours, 2) : null;

        $result = [
            'project_id' => $project->id,
            'project' => $project->name,
            'client' => $project->client?->name,
            'billing_type' => $project->billing_type?->value,
            'outsourced' => $outsourced,
            'hours' => round($hours, 2),
            'meters' => round($meters, 2),
            'client_hour_rate' => $clientHourRate,
            'client_meter_rate' => $project->client_meter_rate !== null ? (float) $project->client_meter_rate : null,
            'avg_cost_per_hour' => $avgCostPerHour,
            'margin_per_hour' => ($clientHourRate !== null && $avgCostPerHour !== null)
                ? round($clientHourRate - $avgCostPerHour, 2) : null,
            'revenue' => $revenue,
            'labour_cost' => round($labourCost, 2),
            'expenses' => round($expenses, 2),
            'subcontractor_cost' => round($subcontract, 2),
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $margin,
            'health' => $health,
        ];

        if ($withBreakdown) {
            $result['day_breakdown'] = $this->dayBreakdown($project, $from, $to);
            $result['month_breakdown'] = $this->monthBreakdown($project, $from, $to);
        }

        return $result;
    }

    /**
     * Feature 3 — the daily production P&L for one project, with a per-worker
     * breakdown per day, monthly rollups, and KPI figures.
     *
     * INCOME per attendance row uses the CLIENT rate: a project-designation rate
     * for the worker's designation if one is set, else the project's single
     * client_hour_rate / client_meter_rate. COST is the worker's frozen day total
     * (what payroll pays), plus that date's approved project expenses.
     *
     * @return array{days: list<array<string, mixed>>, months: list<array<string, mixed>>, totals: array<string, mixed>, kpis: array<string, mixed>}
     */
    public function dailyPnl(Project $project, ?string $from = null, ?string $to = null): array
    {
        $companyId = (int) $project->company_id;

        /** @var Collection<int, ProjectDesignationRate> $rates */
        $rates = ProjectDesignationRate::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('project_id', $project->id)
            ->get()->keyBy('designation_id');

        $clientHour = (float) ($project->client_hour_rate ?? 0);
        $clientMeter = (float) ($project->client_meter_rate ?? 0);

        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $project->id)
            ->whereIn('status', ['present', 'late', 'early_leave'])
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)
                ->select('id', 'full_name', 'designation', 'designation_id')])
            ->orderBy('date')
            ->get();

        /** @var Collection<string, float> $expensesByDate */
        $expensesByDate = Expense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('project_id', $project->id)->where('approved', true)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->selectRaw('date, COALESCE(SUM(total),0) as total')
            ->groupBy('date')->pluck('total', 'date');

        // Group rows by date, building the per-worker lines as we go.
        $byDate = [];
        foreach ($rows as $r) {
            $date = $r->date->toDateString();
            $hours = (float) $r->hours_worked;
            $income = $this->rowIncome($r, $rates, $clientHour, $clientMeter);
            $cost = (float) $r->total_amount;

            $byDate[$date] ??= ['hours' => 0.0, 'income' => 0.0, 'labour' => 0.0, 'workers' => []];
            $byDate[$date]['hours'] += $hours;
            $byDate[$date]['income'] += $income;
            $byDate[$date]['labour'] += $cost;
            $byDate[$date]['workers'][] = [
                'worker' => $r->employee?->full_name,
                'designation' => $r->employee?->designation,
                'hours' => round($hours, 2),
                'client_rate' => $this->unitClientRate($r, $rates, $clientHour, $clientMeter),
                'worker_rate' => $this->unitWorkerRate($r),
                'income' => round($income, 2),
                'cost' => round($cost, 2),
                'profit' => round($income - $cost, 2),
            ];
        }

        // Assemble day rows (newest first) + accumulate month + total figures.
        $days = [];
        $months = [];
        $tHours = $tIncome = $tLabour = $tExpenses = 0.0;

        foreach ($byDate as $date => $d) {
            $expenses = (float) ($expensesByDate[$date] ?? 0);
            $cost = round($d['labour'] + $expenses, 2);
            $income = round($d['income'], 2);
            $profit = round($income - $cost, 2);
            [$margin, $health] = $this->classify($income, $cost, $profit);
            $margin ??= 0.0;

            $days[] = [
                'date' => $date,
                'workers_count' => count($d['workers']),
                'hours' => round($d['hours'], 2),
                'income' => $income,
                'labour' => round($d['labour'], 2),
                'expenses' => round($expenses, 2),
                'profit' => $profit,
                'margin' => $margin,
                'health' => $health,
                'workers' => $d['workers'],
            ];

            $tHours += $d['hours'];
            $tIncome += $d['income'];
            $tLabour += $d['labour'];
            $tExpenses += $expenses;

            $month = substr($date, 0, 7);
            $months[$month] ??= ['income' => 0.0, 'labour' => 0.0, 'expenses' => 0.0, 'hours' => 0.0, 'days' => []];
            $months[$month]['income'] += $d['income'];
            $months[$month]['labour'] += $d['labour'];
            $months[$month]['expenses'] += $expenses;
            $months[$month]['hours'] += $d['hours'];
            $months[$month]['days'][$date] = true;
        }

        usort($days, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        $monthRows = [];
        foreach ($months as $month => $m) {
            $income = round($m['income'], 2);
            $cost = round($m['labour'] + $m['expenses'], 2);
            $profit = round($income - $cost, 2);
            [$margin] = $this->classify($income, $cost, $profit);
            $margin ??= 0.0;
            $monthRows[] = [
                'month' => $month,
                'days_worked' => count($m['days']),
                'hours' => round($m['hours'], 2),
                'income' => $income,
                'labour' => round($m['labour'], 2),
                'expenses' => round($m['expenses'], 2),
                'cost' => $cost,
                'profit' => $profit,
                'margin' => $margin,
            ];
        }
        usort($monthRows, fn (array $a, array $b): int => strcmp($b['month'], $a['month']));

        $totalCost = round($tLabour + $tExpenses, 2);
        $totalProfit = round($tIncome - $totalCost, 2);
        [$totalMargin] = $this->classify(round($tIncome, 2), $totalCost, $totalProfit);
        $totalMargin ??= 0.0;

        return [
            'days' => $days,
            'months' => $monthRows,
            'totals' => [
                'hours' => round($tHours, 2),
                'income' => round($tIncome, 2),
                'labour' => round($tLabour, 2),
                'expenses' => round($tExpenses, 2),
                'cost' => $totalCost,
                'profit' => $totalProfit,
                'margin' => $totalMargin,
            ],
            'kpis' => $this->pnlKpis($project, $days, $monthRows, $totalProfit, $totalMargin),
        ];
    }

    /**
     * The client revenue an attendance row earns: a project-designation rate for
     * the worker's designation if set, else the project's single client rate.
     *
     * @param  Collection<int, ProjectDesignationRate>  $rates
     */
    private function rowIncome(Attendance $r, Collection $rates, float $clientHour, float $clientMeter): float
    {
        $hours = (float) $r->hours_worked;
        $qty = (float) ($r->quantity ?? 0);
        $isHalf = $r->day_type?->value === 'half';
        $isPerMeter = $r->day_type?->value === 'per_meter';

        $designationId = $r->employee?->designation_id;
        $rate = $designationId !== null ? $rates->get($designationId) : null;

        if ($rate instanceof ProjectDesignationRate) {
            $client = (float) $rate->client_rate;

            return match ($rate->rate_type) {
                ProjectRateType::PerHour => $hours * $client,
                ProjectRateType::PerDay => ($isHalf ? 0.5 : 1.0) * $client,
                ProjectRateType::PerMeter => $qty * $client,
            };
        }

        return $isPerMeter ? $qty * $clientMeter : $hours * $clientHour;
    }

    /**
     * @param  Collection<int, ProjectDesignationRate>  $rates
     */
    private function unitClientRate(Attendance $r, Collection $rates, float $clientHour, float $clientMeter): float
    {
        $rate = $r->employee?->designation_id !== null ? $rates->get($r->employee->designation_id) : null;

        if ($rate instanceof ProjectDesignationRate) {
            return (float) $rate->client_rate;
        }

        return $r->day_type?->value === 'per_meter' ? $clientMeter : $clientHour;
    }

    /**
     * The rate the worker actually COSTS us — always the frozen pay snapshot.
     * Never project_designation_rates.worker_rate: that field is reference-only
     * (salary-structure rule 2026-08-12) and the row's real cost is priced from
     * the profile/wage-history snapshot, so showing anything else would make the
     * displayed rate disagree with the summed cost.
     */
    private function unitWorkerRate(Attendance $r): float
    {
        return (float) ($r->hourly_rate_snapshot ?? $r->wage_rate_snapshot ?? 0);
    }

    /**
     * KPI cards: today, this month, whole project, and days left to the end date.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  list<array<string, mixed>>  $months
     * @return array<string, mixed>
     */
    private function pnlKpis(Project $project, array $days, array $months, float $totalProfit, float $totalMargin): array
    {
        $today = Carbon::now()->toDateString();
        $thisMonth = Carbon::now()->format('Y-m');

        $todayRow = collect($days)->firstWhere('date', $today);
        $monthRow = collect($months)->firstWhere('month', $thisMonth);

        $daysRemaining = null;
        if ($project->end_date !== null) {
            $daysRemaining = (int) max(0, Carbon::now()->startOfDay()
                ->diffInDays($project->end_date->copy()->startOfDay(), false));
        }

        return [
            'today' => ['profit' => $todayRow['profit'] ?? 0.0, 'margin' => $todayRow['margin'] ?? 0.0],
            'this_month' => ['profit' => $monthRow['profit'] ?? 0.0, 'margin' => $monthRow['margin'] ?? 0.0],
            'total' => ['profit' => $totalProfit, 'margin' => $totalMargin],
            'days_remaining' => $daysRemaining,
        ];
    }

    /**
     * Dashboard widget: how many projects are profitable / at risk / at a loss.
     * All-time, over every project with any revenue or cost (idle projects — no
     * data — are neutral and counted in none of the three buckets).
     *
     * @return array{profitable: int, at_risk: int, loss: int}
     */
    public function dashboardCounts(int $companyId): array
    {
        $key = 'profit:counts:'.$companyId.':'.md5($this->signature($companyId));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($companyId): array {
            $counts = ['profitable' => 0, 'at_risk' => 0, 'loss' => 0];

            foreach ($this->companyRows($companyId, null, null, null) as $row) {
                match ($row['health']) {
                    'ok' => $counts['profitable']++,
                    'warn' => $counts['at_risk']++,
                    'danger' => $counts['loss']++,
                    default => null,
                };
            }

            return $counts;
        });
    }

    /**
     * Per-project P&L for every project in the company (optionally one client),
     * built from grouped aggregates so it is a handful of queries, not N.
     *
     * @return list<array<string, mixed>>
     */
    private function companyRows(int $companyId, ?string $from, ?string $to, ?int $clientId): array
    {
        $projects = Project::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->with('client:id,name')
            ->orderBy('name')
            ->get();

        return $projects->map(fn (Project $p): array => $this->forProject($p, $from, $to, false))->all();
    }

    /**
     * Grouped attendance sums for ONE project: hours, per-meter quantity, and
     * labour cost (the frozen day totals).
     *
     * @return array{hours: float, meters: float, labour: float}
     */
    private function attendanceAggregate(int $companyId, ?string $from, ?string $to, int $projectId): array
    {
        $row = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->selectRaw('COALESCE(SUM(hours_worked),0) as hours')
            ->selectRaw('COALESCE(SUM(total_amount),0) as labour')
            ->selectRaw("COALESCE(SUM(CASE WHEN day_type = 'per_meter' THEN quantity ELSE 0 END),0) as meters")
            ->first();

        return [
            'hours' => (float) ($row?->getAttribute('hours') ?? 0),
            'labour' => (float) ($row?->getAttribute('labour') ?? 0),
            'meters' => (float) ($row?->getAttribute('meters') ?? 0),
        ];
    }

    private function expenseTotal(int $companyId, ?string $from, ?string $to, int $projectId): float
    {
        return (float) Expense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->where('approved', true)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->sum('total');
    }

    private function subcontractorTotal(int $companyId, ?string $from, ?string $to, int $projectId): float
    {
        return (float) SubcontractorPayment::query()
            ->join('subcontractors', 'subcontractors.id', '=', 'subcontractor_payments.subcontractor_id')
            ->where('subcontractors.company_id', $companyId)
            ->where('subcontractors.project_id', $projectId)
            ->where('subcontractor_payments.status', 'paid')
            ->when($from !== null, fn ($q) => $q->whereDate('subcontractor_payments.payment_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('subcontractor_payments.payment_date', '<=', $to))
            ->sum('subcontractor_payments.amount');
    }

    /**
     * Revenue by billing method. Hourly / per-meter multiply the client rate by
     * the accrued units; every other method (fixed, milestone, unset) bills from
     * the paid sale invoices raised against the project.
     */
    private function revenueFor(Project $project, float $hours, float $meters, ?string $from, ?string $to): float
    {
        return match ($project->billing_type) {
            BillingType::Hourly => (float) ($project->client_hour_rate ?? 0) * $hours,
            BillingType::PerMeter => (float) ($project->client_meter_rate ?? 0) * $meters,
            default => $this->paidInvoiceTotal($project->company_id, $from, $to, $project->id),
        };
    }

    private function paidInvoiceTotal(int $companyId, ?string $from, ?string $to, int $projectId): float
    {
        return (float) Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->where('type', 'sale')
            ->where('payment_status', 'paid')
            ->when($from !== null, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
            ->sum('total');
    }

    /**
     * Day-wise labour P&L for one project. Revenue is per-day only for the
     * unit-billed methods (hourly / per_meter); invoice-billed projects show
     * hours + labour cost with no per-day revenue to attribute.
     *
     * @return list<array{date: string, hours: float, revenue: float|null, cost: float, profit: float|null}>
     */
    private function dayBreakdown(Project $project, ?string $from, ?string $to): array
    {
        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->selectRaw('date')
            ->selectRaw('COALESCE(SUM(hours_worked),0) as hours')
            ->selectRaw('COALESCE(SUM(total_amount),0) as labour')
            ->selectRaw("COALESCE(SUM(CASE WHEN day_type = 'per_meter' THEN quantity ELSE 0 END),0) as meters")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $rows->map(function (Attendance $r) use ($project): array {
            $hours = (float) $r->getAttribute('hours');
            $meters = (float) $r->getAttribute('meters');
            $cost = round((float) $r->getAttribute('labour'), 2);
            $revenue = $this->unitRevenue($project, $hours, $meters);

            return [
                'date' => (string) $r->getAttribute('date'),
                'hours' => round($hours, 2),
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $revenue !== null ? round($revenue - $cost, 2) : null,
            ];
        })->all();
    }

    /**
     * Month-wise labour P&L for one project (same basis as the day breakdown).
     *
     * @return list<array{month: string, hours: float, revenue: float|null, cost: float, profit: float|null, margin: float|null}>
     */
    private function monthBreakdown(Project $project, ?string $from, ?string $to): array
    {
        $monthExpr = $this->monthExpression();

        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->selectRaw("{$monthExpr} as ym")
            ->selectRaw('COALESCE(SUM(hours_worked),0) as hours')
            ->selectRaw('COALESCE(SUM(total_amount),0) as labour')
            ->selectRaw("COALESCE(SUM(CASE WHEN day_type = 'per_meter' THEN quantity ELSE 0 END),0) as meters")
            ->groupByRaw($monthExpr)
            ->orderByRaw($monthExpr)
            ->get();

        return $rows->map(function (Attendance $r) use ($project): array {
            $hours = (float) $r->getAttribute('hours');
            $meters = (float) $r->getAttribute('meters');
            $cost = round((float) $r->getAttribute('labour'), 2);
            $revenue = $this->unitRevenue($project, $hours, $meters);
            $profit = $revenue !== null ? round($revenue - $cost, 2) : null;

            return [
                'month' => (string) $r->getAttribute('ym'),
                'hours' => round($hours, 2),
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => ($revenue !== null && $revenue > 0.0) ? round(($profit / $revenue) * 100, 1) : null,
            ];
        })->all();
    }

    /**
     * Per-unit revenue for a breakdown row: only hourly / per-meter projects can
     * attribute revenue to a slice of time; invoice-billed projects return null.
     */
    private function unitRevenue(Project $project, float $hours, float $meters): ?float
    {
        return match ($project->billing_type) {
            BillingType::Hourly => round((float) ($project->client_hour_rate ?? 0) * $hours, 2),
            BillingType::PerMeter => round((float) ($project->client_meter_rate ?? 0) * $meters, 2),
            default => null,
        };
    }

    /**
     * Margin percent + the traffic-light health. No revenue with a cost is a
     * loss (red); no revenue and no cost is idle (neutral).
     *
     * @return array{0: float|null, 1: string}
     */
    private function classify(float $revenue, float $cost, float $profit): array
    {
        if ($revenue <= 0.0) {
            return [null, $cost > 0.0 ? 'danger' : 'neutral'];
        }

        $margin = round(($profit / $revenue) * 100, 1);

        $health = match (true) {
            $margin > self::MARGIN_GREEN => 'ok',
            $margin >= self::MARGIN_AMBER => 'warn',
            default => 'danger',
        };

        return [$margin, $health];
    }

    /**
     * Table row shape for the report screen + exports.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function rowFrom(array $d): array
    {
        // Columns reconcile: coste_mo (labour) + gastos (expenses + subcontractor)
        // = total cost, so revenue − (coste_mo + gastos) = profit on the row.
        return [
            'project_id' => $d['project_id'],
            'project' => $d['project'],
            'client' => $d['client'] ?? '—',
            'hours' => $d['hours'],
            'revenue' => $d['revenue'],
            'coste_mo' => $d['labour_cost'],
            'gastos' => round((float) $d['expenses'] + (float) $d['subcontractor_cost'], 2),
            'profit' => $d['profit'],
            'margin' => $d['margin'],
        ];
    }

    /**
     * Headline totals across a set of project results.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function figuresFor(array $rows): array
    {
        $revenue = array_sum(array_map(fn (array $r): float => (float) $r['revenue'], $rows));
        $cost = array_sum(array_map(fn (array $r): float => (float) $r['cost'], $rows));
        $profit = round($revenue - $cost, 2);

        return [
            'total_revenue' => round($revenue, 2),
            'total_cost' => round($cost, 2),
            'total_profit' => $profit,
            'avg_margin' => $revenue > 0.0 ? round(($profit / $revenue) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function emptyFigures(): array
    {
        return ['total_revenue' => 0.0, 'total_cost' => 0.0, 'total_profit' => 0.0, 'avg_margin' => 0.0];
    }

    /**
     * A cheap change-signature: any new/edited attendance, expense, project or
     * subcontractor payment moves a MAX(updated_at) and busts every cached key.
     */
    private function signature(int $companyId): string
    {
        $att = Attendance::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        $exp = Expense::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        $prj = Project::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        $sub = SubcontractorPayment::query()
            ->join('subcontractors', 'subcontractors.id', '=', 'subcontractor_payments.subcontractor_id')
            ->where('subcontractors.company_id', $companyId)
            ->max('subcontractor_payments.updated_at');

        return implode('|', [(string) $att, (string) $exp, (string) $prj, (string) $sub]);
    }

    /** Portable "year-month" grouping — SQLite in tests, MySQL in production. */
    private function monthExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', date)"
            : "DATE_FORMAT(date, '%Y-%m')";
    }
}
