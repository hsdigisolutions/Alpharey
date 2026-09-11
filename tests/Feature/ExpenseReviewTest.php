<?php

use App\Enums\UserRole;
use App\Enums\WorkerExpenseStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\WorkerExpense;
use App\Notifications\SystemNotification;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Facades\Notification;
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

it('escalation records the escalator + note and never stamps the worker expense as approved (BUG 3)', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '40');

    $this->actingAs($this->admin)
        ->post("/worker-expenses/{$we->id}/review", ['review_note' => 'Need SA sign-off'])
        ->assertRedirect();

    expect($we->fresh()->status)->toBe(WorkerExpenseStatus::InReview)
        ->and($we->fresh()->approved_by)->toBeNull(); // NOT "approved by {manager}"

    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();
    expect($mirror->escalated_by)->toBe($this->admin->id)
        ->and($mirror->review_note)->toBe('Need SA sign-off');
});

it('notifies the worker + records the SA as decider when approved from the review queue (BUG 4)', function (): void {
    Notification::fake();
    $workerUser = User::factory()->worker()->for($this->company)->create();
    $this->employee->user_id = $workerUser->id;
    $this->employee->save();

    $we = fuelWorkerExpense($this->company, $this->employee, '55');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review");
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $this->actingAs($this->sa)->post("/expense-review/{$mirror->id}/approve")->assertRedirect();

    Notification::assertSentTo($workerUser, SystemNotification::class);
    expect($we->fresh()->status)->toBe(WorkerExpenseStatus::Approved)
        ->and($we->fresh()->approved_by)->toBe($this->sa->id); // decider = the SA, not the manager
});

it('notifies the worker when rejected from the review queue', function (): void {
    Notification::fake();
    $workerUser = User::factory()->worker()->for($this->company)->create();
    $this->employee->user_id = $workerUser->id;
    $this->employee->save();

    $we = fuelWorkerExpense($this->company, $this->employee, '55');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review");
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $this->actingAs($this->sa)->post("/expense-review/{$mirror->id}/reject")->assertRedirect();

    Notification::assertSentTo($workerUser, SystemNotification::class);
    expect($we->fresh()->status)->toBe(WorkerExpenseStatus::Rejected);
});

it('payroll follows the mirror Expense approval, never the WorkerExpense.status (payroll safety, BUG 3)', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '60');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review");

    // Force the WorkerExpense to 'approved' WITHOUT approving the mirror — payroll
    // must still count 0 (it reads the mirror's `approved`, not the status). The
    // counted case (final approval → 60) is proven by the queue-approve test above.
    $we->status = WorkerExpenseStatus::Approved; // status is not fillable — set directly
    $we->save();
    $payroll = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08');
    expect((float) $payroll->getAttribute('reimbursements'))->toBe(0.0);
});

it('exposes an in-review filter + KPI on the Expenses screen (BUG 4)', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '40');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review", ['review_note' => 'check this']);

    $this->actingAs($this->admin)->get('/expenses?approval=in_review')
        ->assertInertia(fn (Assert $p) => $p->component('Expenses/Index')
            ->where('stats.in_review.count', 1)
            ->has('expenses.data', 1)
            ->where('expenses.data.0.review_status', 'in_review')
            ->where('expenses.data.0.escalated_by', $this->admin->name));
});

it('ships full receipt-detail context to the review queue (BUG 2 + BUG 4)', function (): void {
    $we = fuelWorkerExpense($this->company, $this->employee, '40');
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/review", ['review_note' => 'please verify']);

    $this->actingAs($this->sa)->get('/expense-review')
        ->assertInertia(fn (Assert $p) => $p->component('Admin/ExpenseReview')
            ->where('expenses.0.employee', 'León Paz')
            ->where('expenses.0.is_worker_submitted', true)
            ->where('expenses.0.escalated_by', $this->admin->name)
            ->where('expenses.0.review_note', 'please verify'));
});
