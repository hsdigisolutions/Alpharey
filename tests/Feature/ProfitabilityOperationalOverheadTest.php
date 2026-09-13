<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Reports\ProfitabilityService;
use App\Services\Settings\SettingsService;

// Item 7 (2026-09-13) — operational cost % overhead (Option A: ADDITIVE, cost-
// tracking only). The P&L shows a separate "operational overhead" line = Σ(non-
// exempt worker frozen day total × the company op %); the existing revenue / cost
// / profit figures stay byte-identical, and payroll is never touched.
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);

    // Hourly-billed so a full-day worker earns 8 net h × the client rate.
    $this->project = Project::factory()->forCompany($this->company)->create([
        'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);
});

function overheadWorker(bool $exempt = false): Employee
{
    return Employee::factory()->forCompany(test()->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '100', 'designation_id' => null,
        'operational_cost_exempt' => $exempt,
    ]);
}

function logFullDay(Employee $emp, string $date = '2026-09-10'): void
{
    app(AttendanceService::class)->create([
        'employee_id' => $emp->id, 'project_id' => test()->project->id, 'date' => $date,
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
        'check_in' => '08:00', 'check_out' => '17:00',
    ]);
}

it('adds the operational overhead line without changing the existing profit', function (): void {
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);

    logFullDay(overheadWorker());

    $summary = app(ProfitabilityService::class)->forProject($this->project->fresh());

    // Existing figures are byte-identical to the no-overhead world: revenue 8 h ×
    // 20 = 160, labour = frozen daily 100, profit = 60.
    expect((float) $summary['revenue'])->toBe(160.0)
        ->and((float) $summary['labour_cost'])->toBe(100.0)
        ->and((float) $summary['profit'])->toBe(60.0)
        // Overhead is ADDITIVE: 10 % of the 100 frozen day total = 10.
        ->and((float) $summary['operational_cost_pct'])->toBe(10.0)
        ->and((float) $summary['operational_overhead'])->toBe(10.0)
        ->and((float) $summary['profit_after_overhead'])->toBe(50.0);

    // The daily view carries the same additive line + identical base profit.
    $pnl = app(ProfitabilityService::class)->dailyPnl($this->project->fresh());
    expect((float) $pnl['totals']['profit'])->toBe(60.0)
        ->and((float) $pnl['totals']['operational_cost_pct'])->toBe(10.0)
        ->and((float) $pnl['totals']['operational_overhead'])->toBe(10.0)
        ->and((float) $pnl['totals']['profit_after_overhead'])->toBe(50.0);
});

it('leaves the P&L untouched when the operational % is 0 (off)', function (): void {
    // No setting → default 0.
    logFullDay(overheadWorker());

    $summary = app(ProfitabilityService::class)->forProject($this->project->fresh());

    expect((float) $summary['profit'])->toBe(60.0)
        ->and((float) $summary['operational_cost_pct'])->toBe(0.0)
        ->and((float) $summary['operational_overhead'])->toBe(0.0)
        // With no overhead, profit-after-overhead equals profit exactly.
        ->and((float) $summary['profit_after_overhead'])->toBe(60.0);
});

it('excludes an exempt worker from the overhead base', function (): void {
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);

    logFullDay(overheadWorker(exempt: false));       // 100 → counts
    logFullDay(overheadWorker(exempt: true));        // 100 → exempt, ignored

    $summary = app(ProfitabilityService::class)->forProject($this->project->fresh());

    // Labour cost is BOTH workers (200); overhead is only the non-exempt one:
    // 10 % of 100 = 10 (not 20).
    expect((float) $summary['labour_cost'])->toBe(200.0)
        ->and((float) $summary['operational_overhead'])->toBe(10.0);
});

it('never adds overhead on an externalised (subcontracted) project', function (): void {
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);

    // A live subcontractor deal: the crew is not ours, so there is no own-labour
    // overhead to add (the labour basis is the budget, not attendance).
    $sub = new Subcontractor([
        'project_id' => $this->project->id, 'name' => 'Thaekedar SL',
        'status' => 'active', 'agreed_budget' => '500', 'expense_responsibility' => 'thaekedar',
    ]);
    $sub->company_id = $this->company->id;
    $sub->save();
    logFullDay(overheadWorker());

    $summary = app(ProfitabilityService::class)->forProject($this->project->fresh());

    expect((float) $summary['operational_overhead'])->toBe(0.0);
});

// Employer social-security tax (2026-09-13) — the fixed €/day the company pays per
// worker is added to the TRUE labour cost (before profit), and the operational
// overhead is Option A on that true labour cost (wages + employer tax).
it('adds employer tax to true labour cost and the overhead base', function (): void {
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);
    $w = overheadWorker();
    $w->update(['employer_tax_per_day' => '30']);
    logFullDay($w); // 1 worked day

    $s = app(ProfitabilityService::class)->forProject($this->project->fresh());

    // revenue 8h × 20 = 160; wages 100; employer tax 30 (1 day) → true labour 130.
    expect((float) $s['revenue'])->toBe(160.0)
        ->and((float) $s['labour_wages'])->toBe(100.0)
        ->and((float) $s['employer_tax'])->toBe(30.0)
        ->and((float) $s['labour_cost'])->toBe(130.0)
        ->and((float) $s['profit'])->toBe(30.0)          // 160 − 130
        ->and((float) $s['operational_overhead'])->toBe(13.0)   // 10% of 130
        ->and((float) $s['profit_after_overhead'])->toBe(17.0); // 30 − 13
});

it('multiplies employer tax by worked days and reflects it in the daily P&L', function (): void {
    $w = overheadWorker();
    $w->update(['employer_tax_per_day' => '20']);
    logFullDay($w, '2026-09-10');
    logFullDay($w, '2026-09-11');

    $s = app(ProfitabilityService::class)->forProject($this->project->fresh());
    // 2 days × €20 = €40 employer tax; wages 2 × 100 = 200 → true labour 240.
    expect((float) $s['employer_tax'])->toBe(40.0)
        ->and((float) $s['labour_cost'])->toBe(240.0);

    $pnl = app(ProfitabilityService::class)->dailyPnl($this->project->fresh());
    expect((float) $pnl['totals']['employer_tax'])->toBe(40.0)
        ->and((float) $pnl['totals']['labour'])->toBe(200.0)  // wages only
        ->and((float) $pnl['totals']['cost'])->toBe(240.0);   // wages + tax
});

it('keeps employer tax in labour cost even for an operational-exempt worker, but out of the overhead base', function (): void {
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);
    $exempt = overheadWorker(exempt: true);
    $exempt->update(['employer_tax_per_day' => '50']);
    logFullDay($exempt);

    $s = app(ProfitabilityService::class)->forProject($this->project->fresh());
    // Employer tax IS a real cost on everyone: true labour = 100 + 50 = 150.
    expect((float) $s['labour_cost'])->toBe(150.0)
        // …but the exempt worker contributes NOTHING to the overhead base.
        ->and((float) $s['operational_overhead'])->toBe(0.0);
});

it('is byte-identical when employer tax is 0 (the default)', function (): void {
    logFullDay(overheadWorker()); // no employer tax set → default 0

    $s = app(ProfitabilityService::class)->forProject($this->project->fresh());
    // wages only, exactly as before the feature.
    expect((float) $s['employer_tax'])->toBe(0.0)
        ->and((float) $s['labour_cost'])->toBe(100.0)
        ->and((float) $s['profit'])->toBe(60.0);
});
