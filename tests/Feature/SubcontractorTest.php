<?php

use App\Enums\SubcontractorPaymentStatus;
use App\Models\Company;
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

function makeSubcontractor(Company $company, ?int $projectId = null): Subcontractor
{
    $s = new Subcontractor(['name' => 'Thaekedar SL', 'status' => 'active', 'project_id' => $projectId]);
    $s->company_id = $company->id;
    $s->saveQuietly();

    return $s;
}

// ── CRUD + tenancy ──────────────────────────────────────────────────────────

it('creates a subcontractor scoped to the acting company', function (): void {
    $this->actingAs($this->admin)->post('/subcontractors', [
        'name' => 'Obras García', 'status' => 'active',
    ])->assertRedirect();

    $s = Subcontractor::query()->firstOrFail();
    expect($s->name)->toBe('Obras García')->and($s->company_id)->toBe($this->companyA->id);
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
