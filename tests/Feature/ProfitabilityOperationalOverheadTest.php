<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
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

// The Rentabilidad daily/monthly rows carry their OWN operational cost + final
// profit-after-overhead (the full picture at every level), and reconcile to the
// totals.
it('shows per-day and per-month operational cost + final profit in the daily P&L', function (): void {
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);
    $w = overheadWorker();
    $w->update(['employer_tax_per_day' => '20']);
    logFullDay($w, '2026-09-10');
    logFullDay($w, '2026-09-11');

    $pnl = app(ProfitabilityService::class)->dailyPnl($this->project->fresh());

    // Each day: wages 100 + tax 20 = 120 true labour; income 160; profit 40;
    // overhead 10% × 120 = 12; final profit 28.
    $day = $pnl['days'][0];
    expect((float) $day['operational_overhead'])->toBe(12.0)
        ->and((float) $day['profit_after_overhead'])->toBe(28.0);

    // The month + totals reconcile to the sum of the two days.
    expect((float) $pnl['months'][0]['operational_overhead'])->toBe(24.0)
        ->and((float) $pnl['months'][0]['profit_after_overhead'])->toBe(56.0)
        ->and((float) $pnl['totals']['operational_overhead'])->toBe(24.0)
        ->and((float) $pnl['totals']['profit_after_overhead'])->toBe(56.0);
});

// F1 (audit fix) — employer social-security tax is a cost of EMPLOYING the worker,
// borne by their HOME company. A DEPLOYED-IN worker's days (logged under the host)
// must NOT add their employer tax to the host project's P&L — the host reimburses
// wages only. Scoped to actual deployment windows so a TRANSFERRED worker, who was
// genuinely employed by the company on those dates, keeps their historical tax.
it('excludes a deployed-in worker employer tax from the host project P&L', function (): void {
    $own = overheadWorker();
    $own->update(['employer_tax_per_day' => '30']);
    logFullDay($own, '2026-09-10');

    // A worker employed by ANOTHER company, DEPLOYED into this host project.
    $home = Company::factory()->create();
    $deployed = Employee::factory()->forCompany($home)->create([
        'wage_type' => 'daily', 'daily_wage' => '100',
        'employer_tax_per_day' => '100', 'designation_id' => null,
    ]);
    EmployeeDeployment::factory()->create([
        'employee_id' => $deployed->id, 'home_company_id' => $home->id,
        'host_company_id' => $this->company->id, 'project_id' => $this->project->id,
        'deployment_start' => '2026-09-01', 'deployment_end' => null,
        'billing_method' => 'option_a', 'status' => 'active',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $deployed->id,
        'project_id' => $this->project->id, 'date' => '2026-09-11',
        'status' => 'present', 'day_type' => 'full', 'hours_worked' => '8', 'total_amount' => '100',
    ]);

    $s = app(ProfitabilityService::class)->forProject($this->project->fresh());

    // Employer tax = the host's OWN worker only (30), never the deployed worker's 100.
    expect((float) $s['employer_tax'])->toBe(30.0)
        // The deployed worker's WAGES still count as host cost (own 100 + deployed
        // 100), so true labour = 200 wages + 30 own tax = 230.
        ->and((float) $s['labour_cost'])->toBe(230.0);
});

it('keeps a foreign-company worker employer tax when there is NO deployment (transfer artifact)', function (): void {
    // A worker whose CURRENT company is elsewhere but who worked on this project
    // with NO deployment record — a transfer artifact. The company DID employ them
    // on that date, so their employer tax stays a legitimate cost here.
    $elsewhere = Company::factory()->create();
    $moved = Employee::factory()->forCompany($elsewhere)->create([
        'wage_type' => 'daily', 'daily_wage' => '100',
        'employer_tax_per_day' => '40', 'designation_id' => null,
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $moved->id,
        'project_id' => $this->project->id, 'date' => '2026-09-11',
        'status' => 'present', 'day_type' => 'full', 'hours_worked' => '8', 'total_amount' => '100',
    ]);

    $s = app(ProfitabilityService::class)->forProject($this->project->fresh());

    // No deployment window → tax is NOT stripped: 40 counts, true labour = 140.
    expect((float) $s['employer_tax'])->toBe(40.0)
        ->and((float) $s['labour_cost'])->toBe(140.0);
});
