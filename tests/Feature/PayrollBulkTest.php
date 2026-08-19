<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Collection;

/**
 * Feature 5 — payroll bulk select + actions. The month has two states
 * (pending → paid) where "approved" is a stamp on a still-pending row, so:
 * draft = pending+unstamped, approved = pending+stamped, paid = paid.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->month = '2026-08';
});

/** @return Collection<int, Payroll> */
function seedPayrolls(Company $company, string $month, int $count): Collection
{
    for ($i = 0; $i < $count; $i++) {
        $e = Employee::factory()->forCompany($company)->create(['wage_type' => 'hourly', 'wage_rate' => '20']);
        Attendance::factory()->create([
            'company_id' => $company->id, 'employee_id' => $e->id,
            'date' => "$month-01", 'status' => 'present', 'hours_worked' => '8',
            'hourly_rate_snapshot' => '20', 'wage_rate_snapshot' => '20',
            'wage_type_snapshot' => 'hourly', 'total_amount' => '160',
        ]);
    }
    app(PayrollService::class)->calculateMonth($company->id, $month);

    return Payroll::withoutGlobalScopes()->where('company_id', $company->id)->get();
}

it('bulk-approves the selected draft payrolls and skips the already-approved', function (): void {
    $payrolls = seedPayrolls($this->company, $this->month, 3);
    $p0 = $payrolls->first();
    $p0->approved_at = now();
    $p0->save(); // one already approved

    $this->actingAs($this->admin)
        ->post('/payroll/bulk-approve', ['ids' => $payrolls->pluck('id')->all()])
        ->assertRedirect();

    expect(Payroll::withoutGlobalScopes()->whereNotNull('approved_at')->count())->toBe(3);
});

it('bulk-marks approved payrolls paid and skips drafts', function (): void {
    $payrolls = seedPayrolls($this->company, $this->month, 3);
    $payrolls->take(2)->each(function (Payroll $p) {
        $p->approved_at = now();
        $p->save();
    }); // approve 2

    $this->actingAs($this->admin)
        ->post('/payroll/bulk-paid', ['ids' => $payrolls->pluck('id')->all()])
        ->assertRedirect();

    expect(Payroll::withoutGlobalScopes()->where('status', 'paid')->count())->toBe(2)
        ->and(Payroll::withoutGlobalScopes()->where('status', 'pending')->count())->toBe(1);
});

it('exports only the selected payrolls', function (): void {
    $payrolls = seedPayrolls($this->company, $this->month, 2);

    $this->actingAs($this->admin)
        ->get('/payroll/bulk-export?'.http_build_query(['ids' => $payrolls->pluck('id')->all(), 'format' => 'excel']))
        ->assertOk();
});

it('never bulk-acts on another company payroll', function (): void {
    $other = Company::factory()->create();
    $foreign = seedPayrolls($other, $this->month, 1)->first();

    // Acting as our admin, targeting the foreign id — the company scope drops it.
    $this->actingAs($this->admin)
        ->post('/payroll/bulk-approve', ['ids' => [$foreign->id]])
        ->assertRedirect();

    expect($foreign->fresh()->approved_at)->toBeNull();
});

it('denies bulk approve without the payroll.approve gate', function (): void {
    $manager = User::factory()->forCompany($this->company)->create();

    $this->actingAs($manager)->post('/payroll/bulk-approve', ['ids' => [1]])->assertForbidden();
});
