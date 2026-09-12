<?php

namespace App\Services\Reports;

use App\Enums\BearableBy;
use App\Enums\BillingType;
use App\Enums\ExpenseResponsibility;
use App\Enums\ExpenseType;
use App\Enums\MeasurementStatus;
use App\Enums\ProjectRateType;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Measurement;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\Scopes\CompanyScope;
use App\Models\Subcontractor;
use App\Models\SubcontractorPayment;
use App\Models\TaskProgress;
use App\Services\Attendance\AttendanceService;
use Illuminate\Database\Eloquent\Builder;
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

    /** The attendance statuses that count as a worked (billable) day. */
    private const WORKED_STATUSES = ['present', 'late', 'early_leave'];

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
        $labourFromAttendance = $agg['labour'];

        // Per-meter billing earns from APPROVED measurements (spec C8) — the
        // workers themselves are usually paid a daily rate, so attendance rows
        // carry no metre quantity. Other billing types keep the attendance
        // quantity (a per-meter-PAID worker on a non-metre project).
        $meters = $project->billing_type === BillingType::PerMeter
            ? $this->approvedMeters($project->id, $from, $to)
            : $agg['meters'];

        // Item 4 — expenses split by who bears them. COMPANY-side (company /
        // employee / unbillable) are always our cost; CLIENT-billable are a
        // pass-through the client reimburses: excluded from cost on unit-billed
        // projects (hourly/task/per_meter — revenue doesn't include them), kept in
        // cost on invoice-billed projects (fixed/milestone — the paid invoice
        // already includes them, so they net; excluding would double-credit).
        $exp = $this->expensesByBearer($project->company_id, $from, $to, $project->id);
        $isUnitBilled = $this->isUnitBilled($project);
        $clientInCost = ! $isUnitBilled;
        $expenses = round($exp['operational'] + ($clientInCost ? $exp['client'] : 0.0), 2);
        $subcontract = $this->subcontractorTotal($project->company_id, $from, $to, $project->id);

        [$revenue, $revenueBasis] = $this->resolveRevenue($project, $hours, $meters, $from, $to);

        // COST rules (thaekedar DEAL model, confirmed 2026-08-13) — exactly one
        // labour basis, never our own crew's wages on an externalised project:
        //
        //   subcontractor with an agreed_budget → cost = the FULL budget the
        //     moment the deal exists (it is committed money, regardless of how
        //     much has been paid out yet). Scenario A: expenses are HIS, ours
        //     add nothing. Scenario B: our approved expenses add on top.
        //   subcontractor with NULL budget (legacy) → paid payments + expenses
        //     (the pre-deal behaviour, until an admin fills the budget in).
        //   outsourced flag (no subcontractor) → the flat outsource_cost.
        //   none of the above → our own attendance labour + expenses.
        //
        // Cancelled deals never cost; completed ones keep costing (committed).
        $outsourced = (bool) $project->outsourced;
        $subs = Subcontractor::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'agreed_budget', 'expense_responsibility']);
        $hasSubcontractor = $subs->isNotEmpty();

        if ($hasSubcontractor) {
            $budgeted = $subs->filter(fn (Subcontractor $s) => $s->agreed_budget !== null);
            $budgetCost = round((float) $budgeted->sum(fn (Subcontractor $s) => (float) $s->agreed_budget), 2);

            // Legacy records (no budget yet): their PAID payments stay the cost.
            $legacyIds = $subs->filter(fn (Subcontractor $s) => $s->agreed_budget === null)->pluck('id');
            $legacyPayments = $legacyIds->isEmpty() ? 0.0 : round((float) SubcontractorPayment::query()
                ->whereIn('subcontractor_id', $legacyIds)
                ->where('status', 'paid')
                ->when($from !== null, fn ($q) => $q->whereDate('payment_date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('payment_date', '<=', $to))
                ->sum('amount'), 2);

            // Expenses are OUR cost only when a deal says WE bear them — or on
            // a pure-legacy project (pre-deal behaviour kept verbatim).
            $weBearExpenses = $budgeted->isEmpty()
                || $subs->contains(fn (Subcontractor $s) => $s->expense_responsibility === ExpenseResponsibility::Ours);

            $subcontract = round($budgetCost + $legacyPayments, 2);
            $labourCost = 0.0;
            $cost = round($subcontract + ($weBearExpenses ? $expenses : 0.0), 2);
        } elseif ($outsourced) {
            $labourCost = (float) ($project->outsource_cost ?? 0.0);
            $cost = round($labourCost + $expenses, 2);
        } else {
            $labourCost = $labourFromAttendance;
            $cost = round($labourCost + $expenses, 2);
        }
        $revenue = round($revenue, 2);
        $profit = round($revenue - $cost, 2);

        [$margin, $health] = $this->classify($revenue, $cost, $profit);

        // A project with no revenue basis configured at all (no billing rate, no
        // paid invoice, no fixed-contract budget) is NOT a loss — it is simply
        // unmeasured. Show it neutral instead of a fake −100 % red loss, which
        // was the "quite off" P&L the client reported (2026-09, Option 3).
        if ($revenueBasis === 'not_configured') {
            $health = 'neutral';
            $margin = null;
        }

        $clientHourRate = $project->client_hour_rate !== null ? (float) $project->client_hour_rate : null;
        $avgCostPerHour = $hours > 0 ? round($labourCost / $hours, 2) : null;

        $result = [
            'project_id' => $project->id,
            'project' => $project->name,
            'client' => $project->client?->name,
            'billing_type' => $project->billing_type?->value,
            'revenue_basis' => $revenueBasis,
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
            // Item 4 — the two labeled expense sections + per-expense detail.
            'expense_breakdown' => [
                'operational' => ['total' => $exp['operational'], 'items' => $exp['items']['operational']],
                'client_billable' => ['total' => $exp['client'], 'in_cost' => $clientInCost, 'items' => $exp['items']['client']],
            ],
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
            ->whereIn('status', self::WORKED_STATUSES)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)
                ->select('id', 'full_name', 'designation', 'designation_id')])
            ->orderBy('date')
            ->get();

        // Item 4 — split the per-day expenses by who bears them. OPERATIONAL
        // (company/employee/unbillable/null bearer) is always our cost; CLIENT-
        // billable is a reimbursed pass-through, in the day cost only when the
        // project is invoice-billed (else it is recoverable and excluded).
        // internal_deployment is excluded by TYPE (already counted via attendance).
        $expenseRows = Expense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('project_id', $project->id)->where('approved', true)
            ->where('type', '!=', ExpenseType::InternalDeployment->value)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->selectRaw('date, bearable_by, COALESCE(SUM(total),0) as total')
            ->groupBy('date', 'bearable_by')->get();
        $opByDate = [];      // operational (our cost) per date
        $clientByDate = [];  // client-billable per date
        foreach ($expenseRows as $er) {
            $d = substr((string) $er->getAttribute('date'), 0, 10);
            $amt = (float) $er->getAttribute('total');
            if ($er->bearable_by === BearableBy::Client) {
                $clientByDate[$d] = ($clientByDate[$d] ?? 0.0) + $amt;
            } else {
                $opByDate[$d] = ($opByDate[$d] ?? 0.0) + $amt;
            }
        }

        // Per-meter billing earns from APPROVED measurements (spec C8): income
        // per worker/day = their approved measured quantity × the client meter
        // rate, while their COST stays the daily/hourly rate they are paid.
        $isPerMeter = $project->billing_type === BillingType::PerMeter;
        // Task-based: per-worker daily income = Σ (their task-progress quantity ×
        // the task's client_rate). Same treatment as per_meter, but the value is
        // already income (the rate lives on the task, not the project).
        $isTaskBased = $project->billing_type === BillingType::TaskBased;

        // Invoice-billed (fixed / milestone / unset) projects earn from PAID
        // invoices, which cannot be attributed to a single day — the daily view
        // must not fabricate hours × rate income the project-level P&L doesn't
        // recognise. Income shows 0 and health stays neutral.
        $isInvoiceBilled = ! $isPerMeter && ! $isTaskBased && $project->billing_type !== BillingType::Hourly;

        // COST parity with forProject() (spec C9): when the project's labour is
        // EXTERNAL — a subcontractor record, or the outsourced flag — our own
        // crew's attendance is NOT our cost. Subcontracted projects cost their
        // paid payments on the payment date; an outsourced flat fee cannot be
        // attributed to a single day (only expenses show per-day there).
        $hasSubcontractor = Subcontractor::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $project->id)
            ->exists();
        $externalLabour = $hasSubcontractor || (bool) $project->outsourced;

        /** @var Collection<string, float> $paymentsByDate */
        $paymentsByDate = $hasSubcontractor
            ? SubcontractorPayment::query()
                ->join('subcontractors', 'subcontractors.id', '=', 'subcontractor_payments.subcontractor_id')
                ->where('subcontractors.company_id', $companyId)
                ->where('subcontractors.project_id', $project->id)
                ->where('subcontractor_payments.status', 'paid')
                ->when($from !== null, fn ($q) => $q->whereDate('subcontractor_payments.payment_date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('subcontractor_payments.payment_date', '<=', $to))
                ->selectRaw('subcontractor_payments.payment_date as pdate')
                ->selectRaw('COALESCE(SUM(subcontractor_payments.amount),0) as total')
                ->groupBy('pdate')
                ->pluck('total', 'pdate')
                ->map(fn ($v): float => (float) $v)
            : collect();
        $measuredRows = $isPerMeter
            ? Measurement::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('project_id', $project->id)
                ->where('status', MeasurementStatus::Approved->value)
                ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
                ->selectRaw('date, employee_id, COALESCE(SUM(quantity),0) as qty')
                ->groupBy('date', 'employee_id')
                ->get()
            : collect();
        // date => [employee_id => qty]; employee_id may be null (unassigned production).
        $measuredByDate = [];
        foreach ($measuredRows as $m) {
            $mDate = substr((string) $m->getAttribute('date'), 0, 10);
            $measuredByDate[$mDate][$m->getAttribute('employee_id') ?? 0] =
                ($measuredByDate[$mDate][$m->getAttribute('employee_id') ?? 0] ?? 0.0) + (float) $m->getAttribute('qty');
        }

        // Task-based: date => [employee_id => income] (already quantity × client_rate).
        $taskIncomeRows = $isTaskBased
            ? $this->taskProgressQuery($project->id, $from, $to)
                ->selectRaw('task_progress.date as d, task_progress.employee_id as emp, COALESCE(SUM(task_progress.quantity * production_tasks.client_rate),0) as inc')
                ->groupBy('task_progress.date', 'task_progress.employee_id')
                ->get()
            : collect();
        $taskIncomeByDate = [];
        foreach ($taskIncomeRows as $ti) {
            $tDate = substr((string) $ti->getAttribute('d'), 0, 10);
            $emp = $ti->getAttribute('emp') ?? 0;
            $taskIncomeByDate[$tDate][$emp] = ($taskIncomeByDate[$tDate][$emp] ?? 0.0) + (float) $ti->getAttribute('inc');
        }

        // Task-based DISPLAY detail (Item 1) — DISPLAY ONLY, no money change:
        //   $taskProductionByDate: date => list<{task, unit, rate, quantity, income}>
        //     one sub-line per TASK worked that day, so a mixed-unit day reads
        //     task by task (Alicatado 12 m² × 13 € + Griferías 5 u × 20 €).
        //   $taskWorkerQtyByDate:  date => [employee_id => {qty, units set}] so the
        //     per-worker expand can show what each worker produced (their meters).
        // Both derive from the SAME task_progress × client_rate as taskIncomeByDate,
        // so the sub-line income sums to the day income to the cent.
        $taskProductionByDate = [];
        $taskWorkerQtyByDate = [];
        $taskReworkByDate = [];
        if ($isTaskBased) {
            $prodRows = $this->taskProgressQuery($project->id, $from, $to)
                ->selectRaw('task_progress.date as d, task_progress.employee_id as emp, production_tasks.id as tid, production_tasks.name as tname, production_tasks.unit as tunit, production_tasks.client_rate as trate, COALESCE(SUM(task_progress.quantity),0) as qty')
                ->groupBy('task_progress.date', 'task_progress.employee_id', 'production_tasks.id', 'production_tasks.name', 'production_tasks.unit', 'production_tasks.client_rate')
                ->get();

            $byDateTask = [];
            foreach ($prodRows as $pr) {
                $d = substr((string) $pr->getAttribute('d'), 0, 10);
                $tid = (int) $pr->getAttribute('tid');
                $qty = (float) $pr->getAttribute('qty');
                $unit = (string) ($pr->getAttribute('tunit') ?? '');
                $byDateTask[$d][$tid] ??= [
                    'task' => (string) $pr->getAttribute('tname'),
                    'unit' => $unit,
                    'rate' => (float) $pr->getAttribute('trate'),
                    'quantity' => 0.0,
                ];
                $byDateTask[$d][$tid]['quantity'] += $qty;

                $emp = $pr->getAttribute('emp') ?? 0;
                $taskWorkerQtyByDate[$d][$emp]['qty'] = ($taskWorkerQtyByDate[$d][$emp]['qty'] ?? 0.0) + $qty;
                $taskWorkerQtyByDate[$d][$emp]['units'][$unit] = true;
            }
            foreach ($byDateTask as $d => $tasks) {
                foreach ($tasks as $t) {
                    $qty = round($t['quantity'], 2);
                    $taskProductionByDate[$d][] = [
                        'task' => $t['task'],
                        'unit' => $t['unit'],
                        'rate' => round($t['rate'], 2),
                        'quantity' => $qty,
                        'income' => round($qty * $t['rate'], 2),
                    ];
                }
            }

            // Rework (client-rejected redo) — shown as a "not billed" line so the
            // penalty is visible; it earns €0 (excluded from revenue above) but its
            // labour is already in the day's cost, so profit drops accordingly.
            $reworkRows = TaskProgress::query()->withoutGlobalScope(CompanyScope::class)
                ->join('production_tasks', 'task_progress.production_task_id', '=', 'production_tasks.id')
                ->where('production_tasks.project_id', $project->id)
                ->where('task_progress.is_rework', true)
                ->when($from !== null, fn ($q) => $q->whereDate('task_progress.date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('task_progress.date', '<=', $to))
                ->selectRaw('task_progress.date as d, production_tasks.name as tname, production_tasks.unit as tunit, COALESCE(SUM(task_progress.quantity),0) as qty')
                ->groupBy('task_progress.date', 'production_tasks.id', 'production_tasks.name', 'production_tasks.unit')
                ->get();
            foreach ($reworkRows as $rr) {
                $d = substr((string) $rr->getAttribute('d'), 0, 10);
                $taskReworkByDate[$d][] = [
                    'task' => (string) $rr->getAttribute('tname'),
                    'unit' => (string) ($rr->getAttribute('tunit') ?? ''),
                    'quantity' => round((float) $rr->getAttribute('qty'), 2),
                ];
            }
        }

        // Group rows by date, building the per-worker lines as we go.
        // Net (displayed) hours — a full-day worker reads 8 h here, not the raw 0.
        $break = $this->breakMinutes($project->company_id);
        $byDate = [];
        foreach ($rows as $r) {
            $date = $r->date->toDateString();
            $hours = $r->displayHoursNet($break);
            $income = match (true) {
                $isPerMeter => round((float) ($measuredByDate[$date][$r->employee_id] ?? 0) * $clientMeter, 2),
                $isTaskBased => round((float) ($taskIncomeByDate[$date][$r->employee_id] ?? 0), 2),
                $isInvoiceBilled => 0.0,
                default => $this->rowIncome($r, $rates, $clientHour, $clientMeter, $break),
            };
            // External labour (subcontracted / outsourced): the crew's wages
            // are the thaekedar's cost, not ours.
            $cost = $externalLabour ? 0.0 : (float) $r->total_amount;
            // Consumed — leftovers (measured but no attendance row) are added below.
            if ($isPerMeter) {
                unset($measuredByDate[$date][$r->employee_id]);
            }
            if ($isTaskBased) {
                unset($taskIncomeByDate[$date][$r->employee_id]);
            }

            $byDate[$date] ??= ['hours' => 0.0, 'income' => 0.0, 'labour' => 0.0, 'workers' => []];
            $byDate[$date]['hours'] += $hours;
            $byDate[$date]['income'] += $income;
            $byDate[$date]['labour'] += $cost;
            // Task-based: what this worker produced today (their logged quantity),
            // with a unit only when all their production shares one unit.
            $wq = $isTaskBased ? ($taskWorkerQtyByDate[$date][$r->employee_id] ?? null) : null;

            $byDate[$date]['workers'][] = [
                'worker' => $r->employee?->full_name,
                'designation' => $r->employee?->designation,
                'hours' => round($hours, 2),
                'client_rate' => $this->unitClientRate($r, $rates, $clientHour, $clientMeter),
                'worker_rate' => $this->unitWorkerRate($r),
                'income' => round($income, 2),
                'cost' => round($cost, 2),
                'profit' => round($income - $cost, 2),
                // Task-based-only display fields (null for other billing types).
                'meters' => $wq !== null ? round((float) $wq['qty'], 2) : null,
                'unit' => ($wq !== null && count($wq['units']) === 1) ? (string) array_key_first($wq['units']) : null,
            ];
        }

        // Approved production with no matching attendance row (a worker measured
        // but not punched, or unassigned production) still earns its income.
        if ($isPerMeter) {
            foreach ($measuredByDate as $date => $byWorker) {
                $left = array_sum($byWorker);
                if ($left > 0) {
                    $byDate[$date] ??= ['hours' => 0.0, 'income' => 0.0, 'labour' => 0.0, 'workers' => []];
                    $byDate[$date]['income'] += round($left * $clientMeter, 2);
                }
            }
            ksort($byDate);
        }
        // Same for task-based: progress logged with no matching attendance row
        // (the value is already income = quantity × client_rate).
        if ($isTaskBased) {
            foreach ($taskIncomeByDate as $date => $byWorker) {
                $left = array_sum($byWorker);
                if ($left > 0) {
                    $byDate[$date] ??= ['hours' => 0.0, 'income' => 0.0, 'labour' => 0.0, 'workers' => []];
                    $byDate[$date]['income'] += round($left, 2);
                }
            }
            ksort($byDate);
        }

        // Subcontractor payments land as labour cost on their payment date —
        // the daily totals then reconcile with forProject()'s cost basis.
        foreach ($paymentsByDate as $pdate => $amount) {
            $pdate = substr((string) $pdate, 0, 10);
            $byDate[$pdate] ??= ['hours' => 0.0, 'income' => 0.0, 'labour' => 0.0, 'workers' => []];
            $byDate[$pdate]['labour'] += $amount;
        }
        if ($paymentsByDate->isNotEmpty()) {
            ksort($byDate);
        }

        // Assemble day rows (newest first) + accumulate month + total figures.
        $days = [];
        $months = [];
        $tHours = $tIncome = $tLabour = $tExpenses = 0.0;

        foreach ($byDate as $date => $d) {
            // Operational is always cost; client-billable only on invoice-billed
            // projects (nets the invoice). Unit-billed → recoverable, excluded.
            $op = (float) ($opByDate[$date] ?? 0);
            $cl = (float) ($clientByDate[$date] ?? 0);
            $expenses = round($op + ($isInvoiceBilled ? $cl : 0.0), 2);
            $cost = round($d['labour'] + $expenses, 2);
            $income = round($d['income'], 2);
            $profit = round($income - $cost, 2);
            // Invoice-billed: no per-day revenue to grade against — stay neutral.
            [$margin, $health] = $isInvoiceBilled
                ? [null, 'neutral']
                : $this->classify($income, $cost, $profit);
            $margin ??= 0.0;

            $dayRow = [
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

            // Task-based DISPLAY extras (Item 1): per-task production sub-lines for
            // the day + the derived effective rates (billed / labour / margin per
            // hour, and quantity per hour when the day is a single unit). Money
            // (income/labour/profit) is untouched — these are ratios of it.
            if ($isTaskBased) {
                $prod = $taskProductionByDate[$date] ?? [];
                $totalQty = 0.0;
                $units = [];
                foreach ($prod as $p) {
                    $totalQty += (float) $p['quantity'];
                    $units[$p['unit']] = true;
                }
                $h = $d['hours'];
                $dayRow['production'] = $prod;
                $dayRow['rework'] = $taskReworkByDate[$date] ?? [];
                $dayRow['effective'] = [
                    'per_hour_billed' => $h > 0 ? round($income / $h, 2) : null,
                    'per_hour_labour' => $h > 0 ? round($d['labour'] / $h, 2) : null,
                    'per_hour_margin' => $h > 0 ? round(($income - $d['labour']) / $h, 2) : null,
                    'qty_per_hour' => ($h > 0 && count($units) === 1) ? round($totalQty / $h, 2) : null,
                    'unit' => count($units) === 1 ? (string) array_key_first($units) : null,
                ];
            }

            $days[] = $dayRow;

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
            [$margin] = $isInvoiceBilled ? [null] : $this->classify($income, $cost, $profit);
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
        [$totalMargin] = $isInvoiceBilled ? [null] : $this->classify(round($tIncome, 2), $totalCost, $totalProfit);
        $totalMargin ??= 0.0;

        // Item 4 — the two labeled expense sections (Company operational /
        // Client-billable) + per-expense detail, for the daily-view display.
        $exp = $this->expensesByBearer($companyId, $from, $to, $project->id);

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
            'expense_breakdown' => [
                'operational' => ['total' => $exp['operational'], 'items' => $exp['items']['operational']],
                'client_billable' => ['total' => $exp['client'], 'in_cost' => $isInvoiceBilled, 'items' => $exp['items']['client']],
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
    private function rowIncome(Attendance $r, Collection $rates, float $clientHour, float $clientMeter, int $break): float
    {
        // Net billable hours (a full 08:00–17:00 jornada = 8 h), never the raw 0.
        $hours = $r->displayHoursNet($break);
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

    /** @var array<int, int> per-company break minutes, memoised for the request */
    private array $breakMinutesCache = [];

    /**
     * The per-company break length that Attendance::displayHoursNet() uses, so
     * the P&L's billable hours match every OTHER surface (grid, Today's Report,
     * Reports). Resolved once per company per request.
     */
    private function breakMinutes(int $companyId): int
    {
        return $this->breakMinutesCache[$companyId] ??= app(AttendanceService::class)->breakDurationMinutes($companyId);
    }

    /**
     * Grouped attendance sums for ONE project: hours, per-meter quantity, and
     * labour cost (the frozen day totals).
     *
     * HOURS use Attendance::displayHoursNet() — NOT the raw hours_worked column,
     * which is 0 for a full-day (jornada) worker. On an hourly-billed project
     * that made a full-day worker earn €0 client income; every other surface
     * already uses the net figure, so this makes the P&L agree with them (a
     * full 08:00–17:00 day = 8 net hours). Cost (frozen day totals) and per-
     * meter quantity are unaffected.
     *
     * @return array{hours: float, meters: float, labour: float}
     */
    private function attendanceAggregate(int $companyId, ?string $from, ?string $to, int $projectId): array
    {
        $break = $this->breakMinutes($companyId);
        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            // Worked days only — the same statuses the daily P&L counts, so the
            // Resumen card and the Rentabilidad tab agree by construction.
            ->whereIn('status', self::WORKED_STATUSES)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->get();

        $hours = 0.0;
        $labour = 0.0;
        $meters = 0.0;
        foreach ($rows as $r) {
            $hours += $r->displayHoursNet($break);
            $labour += (float) $r->total_amount;
            if ($r->day_type?->value === 'per_meter') {
                $meters += (float) ($r->quantity ?? 0);
            }
        }

        return [
            'hours' => round($hours, 2),
            'labour' => round($labour, 2),
            'meters' => round($meters, 2),
        ];
    }

    /**
     * Billable metres for a project: APPROVED measurements only (spec C8).
     * Per-meter income comes from the Measurements module — daily production
     * entered and approved there — never from attendance rows: the workers on a
     * per-meter project are usually paid their DAILY rate, so attendance carries
     * no metre quantity and the two sides of the transaction live in different
     * tables. An unapproved measurement is not yet money.
     */
    private function approvedMeters(int $projectId, ?string $from, ?string $to): float
    {
        return (float) Measurement::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('project_id', $projectId)
            ->where('status', MeasurementStatus::Approved->value)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->sum('quantity');
    }

    /**
     * Approved measured metres grouped by a raw SQL expression (date or month),
     * for the per-meter day/month breakdowns.
     *
     * @return Collection<string, float>
     */
    private function approvedMetersBy(string $groupExpr, int $projectId, ?string $from, ?string $to): Collection
    {
        return Measurement::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('project_id', $projectId)
            ->where('status', MeasurementStatus::Approved->value)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->selectRaw("{$groupExpr} as slice")
            ->selectRaw('COALESCE(SUM(quantity),0) as qty')
            ->groupByRaw($groupExpr)
            ->pluck('qty', 'slice')
            ->map(fn ($qty): float => (float) $qty);
    }

    /**
     * Task-based revenue (BillingType::TaskBased): Σ (Production-Task "Log Work"
     * quantity × that task's client_rate) in the window — the admin's daily task
     * progress IS the billing source (no separate Measurements step). A task with
     * no client_rate bills nothing; a project with NO rated task at all is
     * 'not_configured' (neutral, never a fake loss) — mirroring per_meter with no
     * measurements. `unit_price` stays the INTERNAL cost estimate, untouched.
     *
     * @return array{0: float, 1: string}
     */
    private function taskBasedRevenue(int $projectId, ?string $from, ?string $to): array
    {
        $hasRate = ProductionTask::query()->withoutGlobalScope(CompanyScope::class)
            ->where('project_id', $projectId)->whereNotNull('client_rate')->exists();

        if (! $hasRate) {
            return [0.0, 'not_configured'];
        }

        $revenue = (float) $this->taskProgressQuery($projectId, $from, $to)
            ->sum(DB::raw('task_progress.quantity * production_tasks.client_rate'));

        return [round($revenue, 2), 'task_based'];
    }

    /**
     * Task revenue grouped by a raw date/month expression on task_progress.date
     * (for the day/month breakdowns).
     *
     * @return Collection<string, float>
     */
    private function taskProgressRevenueBy(string $groupExpr, int $projectId, ?string $from, ?string $to): Collection
    {
        return $this->taskProgressQuery($projectId, $from, $to)
            ->selectRaw("{$groupExpr} as slice")
            ->selectRaw('COALESCE(SUM(task_progress.quantity * production_tasks.client_rate),0) as rev')
            ->groupByRaw($groupExpr)
            ->pluck('rev', 'slice')
            ->map(fn ($v): float => (float) $v);
    }

    /**
     * The shared task-progress → rated-task join for task-based revenue. Scope
     * dropped (cross-company reads happen for a Super Admin); only tasks WITH a
     * client_rate count (production_tasks has no `date`, so `date` is unambiguous).
     *
     * @return Builder<TaskProgress>
     */
    private function taskProgressQuery(int $projectId, ?string $from, ?string $to): Builder
    {
        return TaskProgress::query()->withoutGlobalScope(CompanyScope::class)
            ->join('production_tasks', 'task_progress.production_task_id', '=', 'production_tasks.id')
            ->where('production_tasks.project_id', $projectId)
            ->whereNotNull('production_tasks.client_rate')
            // Rework (client rejected the work) never bills — the client pays once
            // for the accepted quantity; the redo is a company penalty (its labour
            // still counts via attendance). Excluded here so EVERY revenue reader
            // (project P&L, day/month breakdowns, the daily production lines) drops
            // it in one place. The rework lines are surfaced separately for display.
            ->where('task_progress.is_rework', false)
            ->when($from !== null, fn ($q) => $q->whereDate('task_progress.date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('task_progress.date', '<=', $to));
    }

    /**
     * Unit-billed = the client is billed by hours / tasks / metres (revenue does
     * NOT already include materials). Fixed / milestone / unset are invoice-billed
     * (revenue = paid invoices, which may already include billed materials).
     */
    private function isUnitBilled(Project $project): bool
    {
        return in_array($project->billing_type, [BillingType::Hourly, BillingType::PerMeter, BillingType::TaskBased], true);
    }

    /**
     * Approved project expenses split by who bears them (Item 4), with per-expense
     * detail for the two labeled P&L sections.
     *
     *   operational — company / employee / unbillable bearable: always OUR cost.
     *   client      — client bearable: money the client reimburses (a pass-through
     *                 at cost, profit-neutral). The CALLER decides whether it sits
     *                 in cost (invoice-billed) or is excluded (unit-billed).
     *
     * internal_deployment cross-charges are excluded by TYPE (never a project P&L
     * cost — the deployed labour is already counted via host-company attendance).
     *
     * @return array{operational: float, client: float, items: array{operational: list<array<string, mixed>>, client: list<array<string, mixed>>}}
     */
    private function expensesByBearer(int $companyId, ?string $from, ?string $to, int $projectId): array
    {
        $rows = Expense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->where('approved', true)
            ->where('type', '!=', ExpenseType::InternalDeployment->value)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->with(['vendor:id,name', 'category:id,name'])
            ->get(['id', 'vendor_id', 'expense_category_id', 'date', 'total', 'bearable_by', 'notes']);

        $operational = 0.0;
        $client = 0.0;
        $opItems = [];
        $clItems = [];
        foreach ($rows as $e) {
            $amount = round((float) $e->total, 2);
            // FK-guarded (a belongsTo may resolve null): vendor name → else category
            // → else the free-text note → else a dash, so the row always has a label.
            $categoryName = $e->expense_category_id !== null ? $e->category->name : null;
            $label = '—';
            if ($e->vendor_id !== null) {
                $label = $e->vendor->name;
            } elseif ($categoryName !== null) {
                $label = $categoryName;
            } elseif ($e->notes !== null && $e->notes !== '') {
                $label = $e->notes;
            }
            $item = [
                'vendor' => $label,
                'category' => $categoryName,
                'amount' => $amount,
                'date' => $e->date->toDateString(),
            ];
            if ($e->bearable_by === BearableBy::Client) {
                $client += $amount;
                $clItems[] = $item;
            } else {
                $operational += $amount;
                $opItems[] = $item;
            }
        }

        return [
            'operational' => round($operational, 2),
            'client' => round($client, 2),
            'items' => ['operational' => $opItems, 'client' => $clItems],
        ];
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
     * Revenue AND the basis it was derived from, so the UI can label exactly
     * where each project's income figure comes from (transparency — the client
     * was confused by unexplained P&L numbers, 2026-09).
     *
     *   hourly    → client_hour_rate × hours          (basis 'hourly')
     *   per_meter → client_meter_rate × metres        (basis 'per_meter')
     *   fixed / milestone / unset → paid SALE invoices (basis 'paid_invoices');
     *     when nothing is invoiced yet, the project's fixed-contract `budget`
     *     stands in as expected revenue (basis 'fixed_budget', Option 2). Real
     *     paid invoices ALWAYS win once they exist, so budget never
     *     double-counts alongside real invoicing.
     *   nothing configured at all → 0 revenue, basis 'not_configured' (the P&L
     *     then reads neutral, never a fake −100 % loss).
     *
     * @return array{0: float, 1: string}
     */
    private function resolveRevenue(Project $project, float $hours, float $meters, ?string $from, ?string $to): array
    {
        return match ($project->billing_type) {
            BillingType::Hourly => $project->client_hour_rate !== null
                ? [(float) $project->client_hour_rate * $hours, 'hourly']
                : [0.0, 'not_configured'],
            BillingType::PerMeter => $project->client_meter_rate !== null
                ? [(float) $project->client_meter_rate * $meters, 'per_meter']
                : [0.0, 'not_configured'],
            // NEW arm only — hourly / per_meter / invoice / not_configured are all
            // untouched. Task-based earns from Production-Task progress.
            BillingType::TaskBased => $this->taskBasedRevenue($project->id, $from, $to),
            default => $this->invoiceOrBudgetRevenue($project, $from, $to),
        };
    }

    /**
     * The invoice-billed group (fixed / milestone / unset): paid sale invoices,
     * falling back to the fixed-contract budget when nothing is invoiced yet.
     * Paid invoices take priority — the budget is only the estimate.
     *
     * @return array{0: float, 1: string}
     */
    private function invoiceOrBudgetRevenue(Project $project, ?string $from, ?string $to): array
    {
        $paid = $this->paidInvoiceTotal($project->company_id, $from, $to, $project->id);

        if ($paid > 0.0) {
            return [$paid, 'paid_invoices'];
        }

        // The budget is a whole-CONTRACT figure — it cannot be sliced into a
        // date window, so it stands in as revenue only for the full-project
        // view (no from/to). A period-filtered report of an un-invoiced fixed
        // project stays 'not_configured' rather than over-crediting the period.
        $budget = $project->budget !== null ? (float) $project->budget : 0.0;

        if ($budget > 0.0 && $from === null && $to === null) {
            return [$budget, 'fixed_budget'];
        }

        return [0.0, 'not_configured'];
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
        // Fetched (not SQL-summed) so HOURS can use displayHoursNet — a full-day
        // jornada reads 8 h, not the raw 0 that made hourly income read €0.
        $break = $this->breakMinutes($project->company_id);
        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->whereIn('status', self::WORKED_STATUSES)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->get();

        /** @var array<string, array{hours: float, labour: float, meters: float}> $byDate */
        $byDate = [];
        foreach ($rows as $r) {
            $date = $r->date->toDateString();
            $byDate[$date] ??= ['hours' => 0.0, 'labour' => 0.0, 'meters' => 0.0];
            $byDate[$date]['hours'] += $r->displayHoursNet($break);
            $byDate[$date]['labour'] += (float) $r->total_amount;
            if ($r->day_type?->value === 'per_meter') {
                $byDate[$date]['meters'] += (float) ($r->quantity ?? 0);
            }
        }

        // Per-meter billing: the day's metres come from APPROVED measurements.
        $isPerMeter = $project->billing_type === BillingType::PerMeter;
        $measured = $isPerMeter ? $this->approvedMetersBy('date', $project->id, $from, $to) : collect();
        // Task-based billing: the day's revenue comes from Production-Task progress.
        $isTaskBased = $project->billing_type === BillingType::TaskBased;
        $taskRev = $isTaskBased ? $this->taskProgressRevenueBy('date', $project->id, $from, $to) : collect();

        $days = [];
        foreach ($byDate as $date => $agg) {
            $hours = round($agg['hours'], 2);
            $meters = $isPerMeter ? (float) ($measured[$date] ?? 0) : $agg['meters'];
            $cost = round($agg['labour'], 2);
            $revenue = $isTaskBased ? round((float) ($taskRev[$date] ?? 0), 2) : $this->unitRevenue($project, $hours, $meters);

            $days[] = [
                'date' => $date,
                'hours' => $hours,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $revenue !== null ? round($revenue - $cost, 2) : null,
            ];
        }

        // A day with approved production but no attendance still earned money.
        $attDates = array_column($days, 'date');
        foreach ($measured as $date => $qty) {
            $date = substr((string) $date, 0, 10);
            if (! in_array($date, $attDates, true)) {
                $revenue = round((float) ($project->client_meter_rate ?? 0) * (float) $qty, 2);
                $days[] = ['date' => $date, 'hours' => 0.0, 'revenue' => $revenue, 'cost' => 0.0, 'profit' => $revenue];
            }
        }
        // Same for a task-based day with progress logged but no attendance row.
        foreach ($taskRev as $date => $rev) {
            $date = substr((string) $date, 0, 10);
            if (! in_array($date, $attDates, true) && (float) $rev > 0) {
                $days[] = ['date' => $date, 'hours' => 0.0, 'revenue' => round((float) $rev, 2), 'cost' => 0.0, 'profit' => round((float) $rev, 2)];
            }
        }
        usort($days, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $days;
    }

    /**
     * Month-wise labour P&L for one project (same basis as the day breakdown).
     *
     * @return list<array{month: string, hours: float, revenue: float|null, cost: float, profit: float|null, margin: float|null}>
     */
    private function monthBreakdown(Project $project, ?string $from, ?string $to): array
    {
        // Fetched (not SQL-summed) so month HOURS use displayHoursNet too — a
        // full-day jornada reads 8 h, consistent with the day breakdown above.
        $break = $this->breakMinutes($project->company_id);
        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->whereIn('status', self::WORKED_STATUSES)
            ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to))
            ->get();

        /** @var array<string, array{hours: float, labour: float, meters: float}> $byMonth */
        $byMonth = [];
        foreach ($rows as $r) {
            $ym = $r->date->format('Y-m');
            $byMonth[$ym] ??= ['hours' => 0.0, 'labour' => 0.0, 'meters' => 0.0];
            $byMonth[$ym]['hours'] += $r->displayHoursNet($break);
            $byMonth[$ym]['labour'] += (float) $r->total_amount;
            if ($r->day_type?->value === 'per_meter') {
                $byMonth[$ym]['meters'] += (float) ($r->quantity ?? 0);
            }
        }

        // Per-meter billing: the month's metres come from APPROVED measurements.
        $isPerMeter = $project->billing_type === BillingType::PerMeter;
        $measured = $isPerMeter
            ? $this->approvedMetersBy($this->monthExpression(), $project->id, $from, $to)
            : collect();
        // Task-based billing: the month's revenue comes from Production-Task progress.
        $isTaskBased = $project->billing_type === BillingType::TaskBased;
        $taskRev = $isTaskBased
            ? $this->taskProgressRevenueBy($this->monthExpression(), $project->id, $from, $to)
            : collect();

        $monthsOut = [];
        foreach ($byMonth as $ym => $agg) {
            $hours = round($agg['hours'], 2);
            $meters = $isPerMeter ? (float) ($measured[$ym] ?? 0) : $agg['meters'];
            $cost = round($agg['labour'], 2);
            $revenue = $isTaskBased ? round((float) ($taskRev[$ym] ?? 0), 2) : $this->unitRevenue($project, $hours, $meters);
            $profit = $revenue !== null ? round($revenue - $cost, 2) : null;

            $monthsOut[] = [
                'month' => $ym,
                'hours' => $hours,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => ($revenue !== null && $revenue > 0.0) ? round(($profit / $revenue) * 100, 1) : null,
            ];
        }

        // A month with approved production but no attendance still earned money.
        $attMonths = array_column($monthsOut, 'month');
        foreach ($measured as $ym => $qty) {
            if (! in_array((string) $ym, $attMonths, true)) {
                $revenue = round((float) ($project->client_meter_rate ?? 0) * (float) $qty, 2);
                $monthsOut[] = ['month' => (string) $ym, 'hours' => 0.0, 'revenue' => $revenue, 'cost' => 0.0, 'profit' => $revenue, 'margin' => $revenue > 0.0 ? 100.0 : null];
            }
        }
        // Same for a task-based month with progress but no attendance.
        foreach ($taskRev as $ym => $rev) {
            if (! in_array((string) $ym, $attMonths, true) && (float) $rev > 0) {
                $r = round((float) $rev, 2);
                $monthsOut[] = ['month' => (string) $ym, 'hours' => 0.0, 'revenue' => $r, 'cost' => 0.0, 'profit' => $r, 'margin' => 100.0];
            }
        }
        usort($monthsOut, fn (array $a, array $b): int => strcmp($a['month'], $b['month']));

        return $monthsOut;
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
            'revenue_basis' => $d['revenue_basis'],
            'coste_mo' => $d['labour_cost'],
            'gastos' => round((float) $d['expenses'] + (float) $d['subcontractor_cost'], 2),
            'profit' => $d['profit'],
            'margin' => $d['margin'],
            'health' => $d['health'],
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
     * A cheap change-signature: any new/edited attendance, expense, project,
     * subcontractor payment, MEASUREMENT (per-meter income basis) or INVOICE
     * (fixed/milestone income basis) moves a MAX(updated_at) and busts every
     * cached key — approving a measurement or marking an invoice paid must
     * refresh the P&L, not wait out the TTL.
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
        $mea = Measurement::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        $inv = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        // Deal edits (agreed_budget / responsibility) change the cost basis.
        $deal = Subcontractor::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        // Task-based income basis: a new "Log Work" entry or an edited task
        // client_rate must refresh the P&L, not wait out the TTL.
        $tp = TaskProgress::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');
        $tsk = ProductionTask::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->max('updated_at');

        return implode('|', [(string) $att, (string) $exp, (string) $prj, (string) $sub, (string) $mea, (string) $inv, (string) $deal, (string) $tp, (string) $tsk]);
    }

    /** Portable "year-month" grouping — SQLite in tests, MySQL in production. */
    private function monthExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', date)"
            : "DATE_FORMAT(date, '%Y-%m')";
    }
}
