<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFuelRecord;
use App\Models\WorkerExpense;
use App\Services\Payroll\PayrollService;
use App\Services\Workers\WorkerFuelExpenseService;
use Illuminate\Support\Facades\Gate;

/**
 * Vehicle/worker-expense two-gate flow (Part B): worker submit → manager approve
 * (mirror Expense, NOT counted) → admin FINAL approve (expenses.approve_final) →
 * payroll counts it. Separation of duties: a manager cannot single-handedly push
 * money into payroll.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Ana Ruiz', 'daily_wage' => '50', 'wage_type' => 'daily']);
});

it('separates duties: a manager with expenses.approve cannot give final approval, an admin can', function (): void {
    // A Manager granted ONLY manager-level approve (never approve_final).
    $manager = User::factory()->forCompany($this->company)->create();
    $this->actingAs($this->admin)->put("/admin/permissions/{$manager->id}", [
        'permissions' => [['module' => 'expenses', 'can_view' => true, 'can_approve' => true]],
    ])->assertRedirect();

    $g = Gate::forUser($manager->fresh());
    expect($g->allows('expenses.approve'))->toBeTrue()
        ->and($g->allows('expenses.approve_final'))->toBeFalse();

    // A worker fuel expense, manager-approved → mirror Expense created (unapproved).
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'fuel', 'amount' => '40', 'date' => '2026-08-10',
    ]);
    app(WorkerFuelExpenseService::class)->mirrorOnSubmission($we);
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    // The manager CANNOT final-approve in the Expenses tab.
    $this->actingAs($manager)->post("/expenses/{$mirror->id}/approve", ['approved' => true])
        ->assertForbidden();
    expect($mirror->fresh()->approved)->toBeFalse();

    // The admin (bypasses the matrix) CAN.
    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true])
        ->assertRedirect();
    expect($mirror->fresh()->approved)->toBeTrue();
});

it('payroll counts a worker fuel reimbursement ONLY after final approval', function (): void {
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'fuel', 'amount' => '60', 'date' => '2026-08-10',
    ]);

    // Manager approval — mirror created, but payroll must NOT count it yet.
    app(WorkerFuelExpenseService::class)->mirrorOnSubmission($we);
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $before = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08');
    expect((float) $before->getAttribute('reimbursements'))->toBe(0.0);

    // Admin final approval → payroll counts the reimbursement.
    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true]);
    $after = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08', $before);
    expect((float) $after->getAttribute('reimbursements'))->toBe(60.0);
});

it('creates a linked VehicleFuelRecord on final approval so the vehicle Fuel tab shows it', function (): void {
    $vehicle = Vehicle::factory()->for($this->company)->create();
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'fuel', 'amount' => '55', 'date' => '2026-08-12',
    ]);
    $we->vehicle_id = $vehicle->id;
    $we->save();

    app(WorkerFuelExpenseService::class)->mirrorOnSubmission($we);
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();
    expect($mirror->vehicle_id)->toBe($vehicle->id);

    // No fuel record until the FINAL approval.
    expect(VehicleFuelRecord::withoutGlobalScopes()->where('expense_id', $mirror->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true]);

    $rec = VehicleFuelRecord::withoutGlobalScopes()->where('expense_id', $mirror->id)->first();
    expect($rec)->not->toBeNull()
        ->and($rec->vehicle_id)->toBe($vehicle->id)
        ->and((float) $rec->total_cost)->toBe(55.0);

    // Idempotent — re-approving never duplicates the vehicle row.
    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true]);
    expect(VehicleFuelRecord::withoutGlobalScopes()->where('expense_id', $mirror->id)->count())->toBe(1);
});
