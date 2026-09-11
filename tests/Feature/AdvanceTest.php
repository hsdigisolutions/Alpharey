<?php

use App\Enums\AdvanceStatus;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->month = '2026-05';
});

/** An employee with one worked day in the test month (so payroll builds a row). */
function advanceEmployee(Company $company, string $month): Employee
{
    $employee = Employee::factory()->forCompany($company)->create([
        'wage_type' => 'daily', 'daily_wage' => '100',
    ]);
    Attendance::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'date' => $month.'-10', 'status' => 'present', 'day_type' => 'full',
    ]);

    return $employee;
}

it('edits an advance amount and recomputes the payroll deduction (Issue 1)', function (): void {
    $employee = advanceEmployee($this->company, $this->month);
    $advance = Advance::factory()->approved()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '50', 'payroll_month' => $this->month,
    ]);
    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $this->actingAs($this->admin)->post("/advances/{$advance->id}", [
        'amount' => '80', 'request_date' => $this->month.'-05', 'payroll_month' => $this->month, 'payment_method' => 'cash',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((float) $advance->fresh()->amount)->toBe(80.0);
    $payroll = Payroll::query()->withoutGlobalScopes()
        ->where('employee_id', $employee->id)->where('month', $this->month)->first();
    expect((float) $payroll->getAttribute('advance_deductions'))->toBe(80.0);
});

it('refuses to edit a settled (deducted) advance (Issue 1 guard)', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create();
    $advance = Advance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '50', 'payroll_month' => $this->month, 'status' => AdvanceStatus::Deducted,
    ]);

    $this->actingAs($this->admin)->post("/advances/{$advance->id}", [
        'amount' => '80', 'request_date' => $this->month.'-05',
    ])->assertStatus(422);

    expect((float) $advance->fresh()->amount)->toBe(50.0);
});

it('creates an advance with bank transfer + receipt, downloadable + audited (Issue 2)', function (): void {
    Storage::fake('local');
    $employee = Employee::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)->post('/advances', [
        'employee_id' => $employee->id, 'amount' => '120', 'request_date' => $this->month.'-10',
        'payroll_month' => $this->month, 'payment_method' => 'bank_transfer',
        'receipt' => UploadedFile::fake()->create('transfer.pdf', 50, 'application/pdf'),
        'approve' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $advance = Advance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect($advance->payment_method)->toBe('bank_transfer')
        ->and($advance->receipt_path)->not->toBeNull()
        ->and($advance->receipt_name)->toBe('transfer.pdf');
    Storage::disk('local')->assertExists($advance->receipt_path);

    // Gated + audited download.
    $this->actingAs($this->admin)->get("/advances/{$advance->id}/receipt")->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'viewed', 'module' => 'payroll']);
});

it('cannot edit another company advance (tenancy 404)', function (): void {
    $other = Company::factory()->create();
    $employee = Employee::factory()->forCompany($other)->create();
    $advance = Advance::factory()->create([
        'company_id' => $other->id, 'employee_id' => $employee->id, 'amount' => '50', 'payroll_month' => $this->month,
    ]);

    $this->actingAs($this->admin)->post("/advances/{$advance->id}", [
        'amount' => '80', 'request_date' => $this->month.'-05',
    ])->assertNotFound();
});

it('ships this month\'s advances in the payroll payload (Issue 1 list)', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Test Worker']);
    Advance::factory()->approved()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '70', 'payroll_month' => $this->month, 'payment_method' => 'cash',
    ]);

    $this->actingAs($this->admin)->get('/payroll?month='.$this->month)
        ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
            ->has('advances', 1)
            ->where('advances.0.amount', 70)
            ->where('advances.0.payment_method', 'cash')
            ->where('advances.0.editable', true));
});
