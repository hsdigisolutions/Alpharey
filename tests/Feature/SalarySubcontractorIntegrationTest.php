<?php

use App\Enums\PayrollStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Expense;
use App\Models\Measurement;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\Subcontractor;
use App\Models\SubcontractorWorker;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;
use App\Services\Reports\ProfitabilityService;
use App\Services\Subcontractors\SubcontractorService;

/**
 * Full integration review (2026-08-13) of the Salary Structure + Subcontractor
 * Deal modules TOGETHER. Each test exercises a real cross-module chain end to
 * end — attendance snapshot → payroll → project P&L → subcontractor settlement
 * — proving nothing is disconnected and no figure is double-counted.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
    $this->att = app(AttendanceService::class);
    $this->pnl = app(ProfitabilityService::class);
    $this->subs = app(SubcontractorService::class);
});

function deal(Project $project, array $extra = []): Subcontractor
{
    $s = new Subcontractor(array_merge([
        'name' => 'Thaekedar SL', 'status' => 'active', 'project_id' => $project->id,
        'client_amount' => '100', 'agreed_budget' => '80', 'expense_responsibility' => 'thaekedar',
    ], $extra));
    $s->company_id = test()->company->id;
    $s->saveQuietly();

    return $s;
}

function linkOurs(Subcontractor $s, Employee $e): void
{
    $w = new SubcontractorWorker([
        'name' => $e->full_name, 'is_our_employee' => true, 'employee_id' => $e->id,
        'days_worked' => '0', 'agreed_rate' => '0', 'total_agreed' => '0', 'payment_status' => 'pending',
    ]);
    $w->subcontractor_id = $s->id;
    $w->saveQuietly();
}

function fullDays(Employee $e, int $projectId, array $dates): void
{
    foreach ($dates as $d) {
        app(AttendanceService::class)->create([
            'employee_id' => $e->id, 'project_id' => $projectId, 'date' => $d,
            'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present', 'hours_worked' => 8,
        ]);
    }
}

// ── Connection 1: Salary + Subcontractor + Attendance ───────────────────────
it('C1: profile rate freezes, settlement pulls it live, payroll pays it, P&L costs the budget', function (): void {
    $designation = Designation::factory()->create();
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50', 'designation_id' => $designation->id,
    ]);
    $project = Project::factory()->forCompany($this->company)->create([
        'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);
    // A designation worker_rate of 80 that must NEVER touch pay.
    ProjectDesignationRate::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'designation_id' => $designation->id, 'rate_type' => 'per_day',
        'client_rate' => '20', 'worker_rate' => '80',
    ]);
    $s = deal($project, ['agreed_budget' => '1000', 'client_amount' => '1200']);
    linkOurs($s, $employee);

    fullDays($employee, $project->id, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05']);

    // Attendance: profile 50 frozen, not the designation 80.
    $rows = Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->get();
    expect($rows)->toHaveCount(5)
        ->and((float) $rows->sum('total_amount'))->toBe(250.0)
        ->and((float) $rows->first()->wage_rate_snapshot)->toBe(50.0);

    // Settlement pulls the 250 live from attendance.
    $settlement = $this->subs->settlement($s->fresh());
    expect((float) $settlement['our_employees_total'])->toBe(250.0)
        ->and((float) $settlement['our_employees'][0]['total'])->toBe(250.0)
        ->and((float) $settlement['our_employees'][0]['days'])->toBe(5.0);

    // Payroll pays the profile 250 — unaffected by the subcontractor budget.
    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('gross_pay'))->toBe(250.0);

    // P&L: cost = budget (not 250 crew wages); income = client rate × hours.
    $r = $this->pnl->forProject($project->fresh());
    expect($r['cost'])->toBe(1000.0)         // the budget — no double count
        ->and($r['labour_cost'])->toBe(0.0)  // our crew is the thaekedar's cost
        ->and($r['revenue'])->toBe(800.0);   // 20 × 40h, never worker_rate 80
});

// ── Connection 2: Salary + Payroll + Project P&L (no subcontractor) ──────────
it('C2: cost is the frozen snapshots; designation rates never touch cost', function (): void {
    $designation = Designation::factory()->create();
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50', 'designation_id' => $designation->id,
    ]);
    $project = Project::factory()->forCompany($this->company)->create([
        'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);
    ProjectDesignationRate::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'designation_id' => $designation->id, 'rate_type' => 'per_day',
        'client_rate' => '20', 'worker_rate' => '80',
    ]);

    fullDays($employee, $project->id, array_map(fn ($d) => sprintf('2026-06-%02d', $d), range(1, 10)));

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    $r = $this->pnl->forProject($project->fresh());

    expect((float) $payroll->getAttribute('gross_pay'))->toBe(500.0) // 10 × 50 profile
        ->and($r['cost'])->toBe(500.0)      // from snapshots, NOT worker_rate 80
        ->and($r['labour_cost'])->toBe(500.0)
        ->and($r['revenue'])->toBe(1600.0); // 20 client × 80h
});

// ── Connection 3: Measurements + P&L + Subcontractor ────────────────────────
it('C3: per-meter income from measurements, cost from budget, no double count', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $project = Project::factory()->forCompany($this->company)->create([
        'billing_type' => 'per_meter', 'client_meter_rate' => '10',
    ]);
    $s = deal($project); // budget 80, client 100

    // Real attendance labour (200) that must NOT be added on top of the budget.
    fullDays($employee, $project->id, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04']);
    Measurement::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'employee_id' => $employee->id, 'date' => '2026-06-02', 'quantity' => '150', 'approved' => true,
    ]);
    Measurement::factory()->create([ // pending — earns nothing
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'date' => '2026-06-02', 'quantity' => '50', 'approved' => false,
    ]);

    $r = $this->pnl->forProject($project->fresh());

    expect($r['revenue'])->toBe(1500.0)  // 150 approved × 10 — NOT the client_amount 100
        ->and($r['cost'])->toBe(80.0)    // the budget — NOT 80 + 200 labour
        ->and($r['profit'])->toBe(1420.0);
});

// ── Connection 4: Wage History + Payroll + Subcontractor ────────────────────
it('C4: August attendance freezes the history rate through payroll and settlement', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'daily', 'daily_wage' => '70']);
    // History: 50 up to 31 Jul, 70 from 1 Aug.
    foreach ([['50', '2026-01-01', '2026-07-31'], ['70', '2026-08-01', null]] as [$rate, $from, $to]) {
        $r = new EmployeeWageRate(['wage_type' => 'daily', 'rate' => $rate, 'effective_from' => $from, 'is_default' => $to === null]);
        $r->effective_to = $to;
        $r->employee_id = $employee->id;
        $r->company_id = $this->company->id;
        $r->saveQuietly();
    }
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'fixed']);
    $s = deal($project, ['agreed_budget' => '1000', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']);
    linkOurs($s, $employee);

    fullDays($employee, $project->id, array_map(fn ($d) => sprintf('2026-08-%02d', $d), [3, 4, 5, 6, 7, 10, 11, 12, 13, 14]));

    $rows = Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->get();
    expect((float) $rows->first()->wage_rate_snapshot)->toBe(70.0) // history, not 50
        ->and((float) $rows->sum('total_amount'))->toBe(700.0);

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-08');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('gross_pay'))->toBe(700.0); // not 500, not 800

    expect((float) $this->subs->settlement($s->fresh())['our_employees_total'])->toBe(700.0);
});

// ── Connection 5: Monthly Pro-Rata + Subcontractor ──────────────────────────
it('C5: monthly worker pro-rates in payroll; the subcontracted project costs the budget', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'monthly', 'base_salary' => '1500']);
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'fixed']);
    $s = deal($project, ['agreed_budget' => '2000']);
    linkOurs($s, $employee);

    // 18 present weekdays of June's 22 → 1500 ÷ 22 × 18 = 1227,27.
    foreach (['01', '02', '03', '04', '05', '08', '09', '10', '11', '12', '15', '16', '17', '18', '19', '22', '23', '24'] as $d) {
        Attendance::factory()->create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'project_id' => $project->id,
            'date' => "2026-06-{$d}", 'status' => 'present', 'hours_worked' => '0',
            'wage_type_snapshot' => 'monthly', 'wage_rate_snapshot' => null,
            'hourly_rate_snapshot' => null, 'total_amount' => '0',
        ]);
    }

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('gross_pay'))->toBe(1227.27);

    // The project still costs the budget (a monthly worker's site total is 0,
    // so nothing is double-added either way).
    expect($this->pnl->forProject($project->fresh())['cost'])->toBe(2000.0);
});

// ── Part 4: cross-company tenancy on every deal sub-action ──────────────────
it('SEC: blocks every subcontractor sub-action across companies (404)', function (): void {
    $other = Company::factory()->create();
    $foreignProject = Project::factory()->forCompany($other)->create();
    $foreign = new Subcontractor([
        'name' => 'Foreign SL', 'status' => 'active', 'project_id' => $foreignProject->id,
        'agreed_budget' => '80', 'client_amount' => '100', 'expense_responsibility' => 'thaekedar',
    ]);
    $foreign->company_id = $other->id;
    $foreign->saveQuietly();

    $worker = new SubcontractorWorker(['name' => 'X', 'is_our_employee' => false, 'days_worked' => '1', 'agreed_rate' => '1', 'total_agreed' => '1', 'payment_status' => 'pending']);
    $worker->subcontractor_id = $foreign->id;
    $worker->saveQuietly();
    $payment = $this->subs->addPayment($foreign, ['amount' => 5]);

    // Every route bound to the foreign subcontractor / its children → 404.
    $this->get("/subcontractors/{$foreign->id}")->assertNotFound();
    $this->post("/subcontractors/{$foreign->id}/workers", ['name' => 'Y', 'days_worked' => 1, 'agreed_rate' => 1, 'payment_status' => 'pending'])->assertNotFound();
    $this->put("/subcontractors/{$foreign->id}/workers/{$worker->id}", ['name' => 'Y', 'days_worked' => 1, 'agreed_rate' => 1, 'payment_status' => 'pending'])->assertNotFound();
    $this->delete("/subcontractors/{$foreign->id}/workers/{$worker->id}")->assertNotFound();
    $this->post("/subcontractors/{$foreign->id}/payments", ['amount' => 1])->assertNotFound();
    $this->post("/subcontractors/{$foreign->id}/payments/{$payment->id}/paid")->assertNotFound();
    $this->delete("/subcontractors/{$foreign->id}/payments/{$payment->id}")->assertNotFound();

    // Nothing was mutated on the foreign record.
    expect($payment->fresh()->status->value)->toBe('pending')
        ->and(SubcontractorWorker::query()->where('subcontractor_id', $foreign->id)->count())->toBe(1);
});

// ── Connection 6: Expenses + Salary + Subcontractor + P&L ───────────────────
it('C6: a worker reimbursable expense is counted once, never double-charged', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'fixed']);
    fullDays($employee, $project->id, ['2026-06-01', '2026-06-02']); // 100 labour

    // A worker-fronted (reimbursable) project expense — paid back via payroll.
    $reimb = Expense::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id, 'employee_id' => $employee->id,
        'bearable_by' => 'employee', 'is_reimbursable' => true, 'approved' => true,
        'subtotal' => '50', 'total' => '50', 'date' => '2026-06-02',
    ]);
    // A client-borne material expense.
    Expense::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'bearable_by' => 'client', 'approved' => true, 'subtotal' => '100', 'total' => '100', 'date' => '2026-06-03',
    ]);

    // Payroll reimburses the worker's 50 exactly once (on top of the 100 pay).
    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('project_expenses'))->toBe(50.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(150.0); // 100 pay + 50 reimb

    // No subcontractor: P&L cost = labour + each approved expense once.
    expect($this->pnl->forProject($project->fresh())['cost'])->toBe(250.0); // 100 + 50 + 100

    // Subcontractor Scenario A: cost is the budget — expenses fold into the
    // thaekedar's side, never added to ours again.
    $sa = deal($project, ['agreed_budget' => '1000', 'expense_responsibility' => 'thaekedar']);
    expect($this->pnl->forProject($project->fresh())['cost'])->toBe(1000.0);

    // Scenario B: budget + our approved project expenses (still once each).
    $sa->update(['expense_responsibility' => 'ours']);
    expect($this->pnl->forProject($project->fresh())['cost'])->toBe(1150.0); // 1000 + 50 + 100
});

// ── Connection 7: Paid Guard + Subcontractor ────────────────────────────────
it('C7: a paid month freezes attendance but the deal budget can still change', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'fixed']);
    $s = deal($project, ['agreed_budget' => '1000']);
    linkOurs($s, $employee);
    fullDays($employee, $project->id, ['2026-06-01', '2026-06-02']);

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    $payroll->status = PayrollStatus::Paid;
    $payroll->save();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->first();

    // Attendance in the paid month is frozen…
    $this->put("/attendance/{$row->id}", [
        'employee_id' => $employee->id, 'date' => $row->date->toDateString(),
        'mode' => 'project_based', 'day_type' => 'half', 'status' => 'present',
    ])->assertSessionHasErrors('date');

    // …but the deal budget can be re-negotiated, and the P&L follows it.
    $this->put("/subcontractors/{$s->id}", [
        'name' => $s->name, 'status' => 'active', 'expense_responsibility' => 'thaekedar',
        'client_amount' => 1200, 'agreed_budget' => 900, 'project_id' => $project->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((float) $s->fresh()->agreed_budget)->toBe(900.0)
        ->and($this->pnl->forProject($project->fresh())['cost'])->toBe(900.0)
        // The frozen attendance is untouched by the budget change.
        ->and((float) $row->fresh()->total_amount)->toBe(50.0);
});
