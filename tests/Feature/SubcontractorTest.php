<?php

use App\Enums\SubcontractorPaymentStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\SubcontractorWorker;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Subcontractors\SubcontractorService;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

function makeSubcontractor(Company $company, ?int $projectId = null, array $extra = []): Subcontractor
{
    $s = new Subcontractor(array_merge(
        ['name' => 'Thaekedar SL', 'status' => 'active', 'project_id' => $projectId],
        $extra,
    ));
    $s->company_id = $company->id;
    $s->saveQuietly();

    return $s;
}

/** Zero-noise attendance for an employee on a project with a frozen total. */
function subAttendance(Company $company, Employee $employee, int $projectId, string $date, float $total): void
{
    Attendance::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'project_id' => $projectId, 'date' => $date, 'status' => 'present',
        'hours_worked' => '8', 'total_amount' => (string) $total,
    ]);
}

/** Link one of OUR employees (Section A) or an external worker (Section B). */
function linkWorker(Subcontractor $s, array $attrs): SubcontractorWorker
{
    $w = new SubcontractorWorker(array_merge([
        'name' => 'Worker', 'is_our_employee' => false, 'days_worked' => '0',
        'agreed_rate' => '0', 'total_agreed' => '0', 'payment_status' => 'pending',
    ], $attrs));
    $w->subcontractor_id = $s->id;
    $w->saveQuietly();

    return $w;
}

// ── CRUD + tenancy ──────────────────────────────────────────────────────────

it('creates a subcontractor scoped to the acting company', function (): void {
    $this->actingAs($this->admin)->post('/subcontractors', [
        'name' => 'Obras García', 'status' => 'active',
        'expense_responsibility' => 'thaekedar',
        'client_amount' => 100, 'agreed_budget' => 80,
    ])->assertRedirect();

    $s = Subcontractor::query()->firstOrFail();
    expect($s->name)->toBe('Obras García')->and($s->company_id)->toBe($this->companyA->id)
        ->and((float) $s->client_amount)->toBe(100.0)
        ->and((float) $s->agreed_budget)->toBe(80.0)
        ->and($s->expense_responsibility->value)->toBe('thaekedar');
});

it('cannot open another company subcontractor', function (): void {
    $foreign = makeSubcontractor($this->companyB);

    $this->actingAs($this->admin)->get("/subcontractors/{$foreign->id}")->assertNotFound();
});

it('requires subcontractors.view to list', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($user)->get('/subcontractors')->assertForbidden();
});

// ── Workers ─────────────────────────────────────────────────────────────────

it('computes a worker total from days × rate', function (): void {
    $this->actingAs($this->admin);
    $s = makeSubcontractor($this->companyA);

    app(SubcontractorService::class)->addWorker($s, [
        'name' => 'Juan', 'days_worked' => 10, 'agreed_rate' => 80, 'payment_status' => 'pending',
    ]);

    $worker = SubcontractorWorker::query()->firstOrFail();
    expect((float) $worker->total_agreed)->toBe(800.0);
});

it('cannot add a worker to another company subcontractor', function (): void {
    $foreign = makeSubcontractor($this->companyB);

    $this->actingAs($this->admin)->post("/subcontractors/{$foreign->id}/workers", [
        'name' => 'X', 'days_worked' => 1, 'agreed_rate' => 1, 'payment_status' => 'pending',
    ])->assertNotFound();
});

// ── Payments + auto-expense ─────────────────────────────────────────────────

it('numbers payments sequentially', function (): void {
    $this->actingAs($this->admin);
    $s = makeSubcontractor($this->companyA);
    $svc = app(SubcontractorService::class);

    $p1 = $svc->addPayment($s, ['amount' => 500]);
    $p2 = $svc->addPayment($s, ['amount' => 1340]);

    expect($p1->payment_number)->toBe(1)->and($p2->payment_number)->toBe(2);
});

it('creates a linked expense on the subcontractor company when a payment is paid', function (): void {
    $this->actingAs($this->admin);
    $project = Project::factory()->forCompany($this->companyA)->create();
    $s = makeSubcontractor($this->companyA, $project->id);
    $svc = app(SubcontractorService::class);
    $payment = $svc->addPayment($s, ['amount' => 500, 'payment_date' => '2026-07-01']);

    $svc->markPaid($payment->fresh());

    $payment->refresh();
    expect($payment->status)->toBe(SubcontractorPaymentStatus::Paid)
        ->and($payment->expense_id)->not->toBeNull();

    $expense = Expense::query()->withoutGlobalScopes()->findOrFail($payment->expense_id);
    expect($expense->company_id)->toBe($this->companyA->id)      // the subcontractor's company
        ->and($expense->project_id)->toBe($project->id)
        ->and((float) $expense->total)->toBe(500.0);
});

it('ships the settlement and expenses payload on the detail page', function (): void {
    $this->actingAs($this->admin);
    $project = Project::factory()->forCompany($this->companyA)->create();
    $s = makeSubcontractor($this->companyA, $project->id, [
        'client_amount' => '100', 'agreed_budget' => '80', 'expense_responsibility' => 'thaekedar',
    ]);
    Expense::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $project->id,
        'approved' => true, 'total' => '20', 'date' => '2026-06-03',
    ]);

    $this->get("/subcontractors/{$s->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subcontractor.client_amount', 100)
            ->where('subcontractor.agreed_budget', 80)
            ->where('subcontractor.our_profit', 20)
            ->where('settlement.thaekedar_profit', 60) // 80 − 0 − 0 − 20
            ->where('settlement.still_to_pay', 60)
            ->has('expenses', 1));
});

// ── The live settlement (confirmed model 2026-08-13) ────────────────────────

it('computes the Scenario A settlement — thaekedar bears all expenses', function (): void {
    // The spec's exact figures: budget 80 − our employees 30 − external 20 −
    // expenses 20 = thaekedar profit 10; advance 5 paid → still to pay 5.
    // Our profit = client 100 − budget 80 = 20 (expenses are HIS problem).
    $this->actingAs($this->admin);
    $project = Project::factory()->forCompany($this->companyA)->create();
    $s = makeSubcontractor($this->companyA, $project->id, [
        'client_amount' => '100', 'agreed_budget' => '80', 'expense_responsibility' => 'thaekedar',
    ]);

    // Our employee: 30 € of LIVE attendance on the project.
    $employee = Employee::factory()->forCompany($this->companyA)->create(['full_name' => 'Carlos Obrero']);
    linkWorker($s, ['name' => 'Carlos Obrero', 'is_our_employee' => true, 'employee_id' => $employee->id]);
    subAttendance($this->companyA, $employee, $project->id, '2026-06-02', 30);

    // External worker: 20 € manual.
    linkWorker($s, ['name' => 'Ali Khan', 'days_worked' => '4', 'agreed_rate' => '5', 'total_agreed' => '20']);

    // Approved project expense: 20 €.
    Expense::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $project->id,
        'approved' => true, 'total' => '20', 'date' => '2026-06-03',
    ]);

    // Advance of 5 € already paid.
    $svc = app(SubcontractorService::class);
    $svc->markPaid($svc->addPayment($s, ['amount' => 5, 'payment_date' => '2026-06-04'])->fresh());

    $settlement = $svc->settlement($s->fresh());

    expect((float) $settlement['our_employees_total'])->toBe(30.0)
        ->and((float) $settlement['external_workers_total'])->toBe(20.0)
        ->and((float) $settlement['expenses_total'])->toBe(20.0)
        ->and((float) $settlement['thaekedar_profit'])->toBe(10.0)
        ->and((float) $settlement['paid_so_far'])->toBe(5.0)
        ->and((float) $settlement['still_to_pay'])->toBe(5.0)
        ->and((float) $settlement['our_profit'])->toBe(20.0);
});

it('computes the Scenario B settlement — we bear the expenses', function (): void {
    // Budget 80 − employees 30 − external 35 = thaekedar profit 15 (expenses
    // NOT deducted from him). Our profit = 100 − 80 − our 15 € expenses = 5.
    $this->actingAs($this->admin);
    $project = Project::factory()->forCompany($this->companyA)->create();
    $s = makeSubcontractor($this->companyA, $project->id, [
        'client_amount' => '100', 'agreed_budget' => '80', 'expense_responsibility' => 'ours',
    ]);

    $employee = Employee::factory()->forCompany($this->companyA)->create();
    linkWorker($s, ['name' => 'Nuestro', 'is_our_employee' => true, 'employee_id' => $employee->id]);
    subAttendance($this->companyA, $employee, $project->id, '2026-06-02', 30);

    linkWorker($s, ['name' => 'Raza', 'days_worked' => '7', 'agreed_rate' => '5', 'total_agreed' => '35']);

    Expense::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $project->id,
        'approved' => true, 'total' => '15', 'date' => '2026-06-03',
    ]);

    $svc = app(SubcontractorService::class);
    $svc->markPaid($svc->addPayment($s, ['amount' => 5])->fresh());

    $settlement = $svc->settlement($s->fresh());

    expect((float) $settlement['thaekedar_profit'])->toBe(15.0) // expenses stay OUT of his side
        ->and((float) $settlement['still_to_pay'])->toBe(10.0)
        ->and((float) $settlement['our_profit'])->toBe(5.0);    // 100 − 80 − 15
});

it('pulls our-employee cost LIVE from attendance, never from the typed worker row', function (): void {
    $this->actingAs($this->admin);
    $project = Project::factory()->forCompany($this->companyA)->create();
    $s = makeSubcontractor($this->companyA, $project->id, [
        'agreed_budget' => '80', 'expense_responsibility' => 'thaekedar',
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30',
    ]);

    $employee = Employee::factory()->forCompany($this->companyA)->create(['full_name' => 'Ahmad']);
    // The typed figures on the link row are IGNORED for our employees.
    linkWorker($s, ['name' => 'Ahmad', 'is_our_employee' => true, 'employee_id' => $employee->id,
        'days_worked' => '99', 'agreed_rate' => '99', 'total_agreed' => '9801']);

    subAttendance($this->companyA, $employee, $project->id, '2026-06-02', 50);
    subAttendance($this->companyA, $employee, $project->id, '2026-06-03', 50);
    // Outside the subcontract window — must NOT count (confirmed decision 2).
    subAttendance($this->companyA, $employee, $project->id, '2026-07-01', 999);

    $settlement = app(SubcontractorService::class)->settlement($s->fresh());
    $line = $settlement['our_employees'][0];

    expect((float) $settlement['our_employees_total'])->toBe(100.0) // 2 × 50, not 9801, not 1099
        ->and($line['name'])->toBe('Ahmad')
        ->and((float) $line['days'])->toBe(2.0)
        ->and((float) $line['rate'])->toBe(50.0)
        ->and((float) $line['total'])->toBe(100.0);
});

it('refuses to APPROVE the auto-posted subcontractor expense (would double-count)', function (): void {
    $this->actingAs($this->admin);
    $s = makeSubcontractor($this->companyA);
    $svc = app(SubcontractorService::class);
    $payment = $svc->addPayment($s, ['amount' => 500]);
    $svc->markPaid($payment->fresh());

    $expense = Expense::query()->withoutGlobalScopes()->findOrFail($payment->fresh()->expense_id);

    // The P&L already counts the payment — approving the linked Gasto would
    // count the same money twice through the approved-expense sum.
    $this->post("/expenses/{$expense->id}/approve", ['approved' => true])
        ->assertSessionHasErrors('approved');
    expect($expense->fresh()->approved)->toBeFalse();
});

it('does not double-charge when a paid payment is marked paid again', function (): void {
    $this->actingAs($this->admin);
    $s = makeSubcontractor($this->companyA);
    $svc = app(SubcontractorService::class);
    $payment = $svc->addPayment($s, ['amount' => 500]);

    $svc->markPaid($payment->fresh());
    $svc->markPaid($payment->fresh());

    expect(Expense::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('removes the expense when a payment is reverted to pending', function (): void {
    $this->actingAs($this->admin);
    $s = makeSubcontractor($this->companyA);
    $svc = app(SubcontractorService::class);
    $payment = $svc->addPayment($s, ['amount' => 500]);
    $svc->markPaid($payment->fresh());

    $svc->markPending($payment->fresh());

    $payment->refresh();
    expect($payment->status)->toBe(SubcontractorPaymentStatus::Pending)
        ->and($payment->expense_id)->toBeNull()
        ->and(Expense::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('requires subcontractors.edit to mark a payment paid', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->companyA->id,
        'module' => 'subcontractors', 'can_view' => true,
    ]);
    $s = makeSubcontractor($this->companyA);
    $payment = app(SubcontractorService::class)->addPayment($s, ['amount' => 500]);

    $this->actingAs($user)->post("/subcontractors/{$s->id}/payments/{$payment->id}/paid")->assertForbidden();
    expect(Expense::query()->withoutGlobalScopes()->count())->toBe(0);
});
