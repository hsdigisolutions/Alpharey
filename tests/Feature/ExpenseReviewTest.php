<?php

use App\Enums\UserRole;
use App\Enums\WorkerExpenseStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\WorkerExpense;
use App\Services\Payroll\PayrollService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Part C — "Send to review": either approval level can escalate an expense to a
 * Super-Admin-only queue whose decision is final.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    $this->sa = User::factory()->superAdmin()->create();
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'León Paz', 'wage_type' => 'daily', 'daily_wage' => '50']);
});

function fuelWorkerExpense(Company $company, Employee $employee, string $amount = '40'): WorkerExpense
{
    return WorkerExpense::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'category' => 'fuel', 'amount' => $amount, 'date' => '2026-08-10',
    ]);
}

it('manager sends a worker expense to review — mirror is in_review and payroll does not count it', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '40');

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review")->assertRedirect();

    expect($we->fresh()->status)->toBe(WorkerExpenseStatus::InReview);
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();
    expect($mirror->review_status)->toBe('in_review')
        ->and($mirror->approved)->toBeFalse();

    // Not counted while in review.
    $payroll = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08');
    expect((float) $payroll->getAttribute('reimbursements'))->toBe(0.0);
});

it('the review queue is Super-Admin only', function (): void {
    $this->actingAs($this->admin)->get('/expense-review')->assertForbidden();
    $this->actingAs($this->sa)->get('/expense-review')->assertOk();
});

it('super admin approves from the queue — final approval releases the money', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '75');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review");
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    // It shows in the SA queue.
    $this->actingAs($this->sa)->get('/expense-review')
        ->assertInertia(fn (Assert $p) => $p->component('Admin/ExpenseReview')->has('expenses', 1));

    // SA approves → approved, review cleared, linked worker expense approved.
    $this->actingAs($this->sa)->post("/expense-review/{$mirror->id}/approve")->assertRedirect();
    expect($mirror->fresh()->approved)->toBeTrue()
        ->and($mirror->fresh()->review_status)->toBeNull()
        ->and($we->fresh()->status)->toBe(WorkerExpenseStatus::Approved);

    // Now payroll counts it.
    $payroll = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08');
    expect((float) $payroll->getAttribute('reimbursements'))->toBe(75.0);
});

it('super admin rejects from the queue — counts nowhere', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '90');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review");
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $this->actingAs($this->sa)->post("/expense-review/{$mirror->id}/reject")->assertRedirect();
    expect($mirror->fresh()->approved)->toBeFalse()
        ->and($mirror->fresh()->review_status)->toBeNull()
        ->and($we->fresh()->status)->toBe(WorkerExpenseStatus::Rejected);

    $payroll = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08');
    expect((float) $payroll->getAttribute('reimbursements'))->toBe(0.0);
});

it('admin can send an already-created expense to review from the Expenses tab', function (): void {
    $expense = Expense::factory()->for($this->company)->create(['approved' => false]);

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/review")->assertRedirect();
    expect($expense->fresh()->review_status)->toBe('in_review');
});
