<?php

use App\Enums\MeasurementStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Measurement;
use App\Models\Payroll;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use App\Services\Reports\ProfitabilityService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
});

/** Set a measurement's status directly (the columns are not mass-assignable). */
function setStatus(Measurement $m, MeasurementStatus $status): Measurement
{
    $m->status = $status;
    $m->approved = $status === MeasurementStatus::Approved;
    $m->save();

    return $m;
}

// ── Connection 1 (completeness): rejected measurements earn no P&L income ────
it('excludes rejected measurements from per-meter P&L income', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'per_meter', 'client_meter_rate' => '10',
    ]);

    $approved = Measurement::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'date' => '2026-06-05', 'quantity' => '150']);
    setStatus($approved, MeasurementStatus::Approved);

    $rejected = Measurement::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'date' => '2026-06-05', 'quantity' => '50']);
    setStatus($rejected, MeasurementStatus::Rejected);

    $r = app(ProfitabilityService::class)->forProject($project->fresh());

    // Only the 150 approved metres earn — the 50 rejected earn nothing.
    expect($r['revenue'])->toBe(1500.0)->and($r['meters'])->toBe(150.0);
});

// ── Connection 3: project summary cards move on approve / reject ─────────────
it('moves the summary card counts as measurements are approved and rejected', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'per_meter']);
    $m1 = Measurement::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'quantity' => '100']);
    $m2 = Measurement::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'quantity' => '40']);

    // Both pending initially.
    $this->get("/projects/{$project->id}")->assertInertia(fn (Assert $p) => $p
        ->where('projectMeasurements.summary.pending_qty', fn ($v) => (float) $v === 140.0)
        ->where('projectMeasurements.summary.approved_qty', fn ($v) => (float) $v === 0.0)
        ->where('projectMeasurements.summary.rejected_qty', fn ($v) => (float) $v === 0.0));

    // Approve one, reject the other.
    $this->post("/measurements/{$m1->id}/approve")->assertRedirect();
    $this->post("/measurements/{$m2->id}/reject", ['rejection_reason' => 'Wrong area'])->assertRedirect();

    $this->get("/projects/{$project->id}")->assertInertia(fn (Assert $p) => $p
        ->where('projectMeasurements.summary.pending_qty', fn ($v) => (float) $v === 0.0)
        ->where('projectMeasurements.summary.approved_qty', fn ($v) => (float) $v === 100.0)
        ->where('projectMeasurements.summary.rejected_qty', fn ($v) => (float) $v === 40.0));
});

// ── Connection 10: measurements + tasks have ZERO effect on salary ──────────
it('pays a per-meter worker their daily rate regardless of measurements or tasks', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'per_meter', 'client_meter_rate' => '10']);
    $worker = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'daily', 'daily_wage' => '50']);

    // 5 full days at 50 €/day → the ONLY basis for pay = 250 €.
    foreach (range(1, 5) as $d) {
        Attendance::factory()->create([
            'company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $project->id,
            'date' => sprintf('2026-05-%02d', $d), 'status' => 'present', 'day_type' => 'full',
            'hours_worked' => '8', 'overtime_hours' => '0',
            'wage_type_snapshot' => 'daily', 'wage_rate_snapshot' => '50', 'total_amount' => '50',
        ]);
    }

    // A large approved measurement and a task with a big completed_quantity +
    // unit_price — neither must touch the payslip.
    $m = Measurement::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'employee_id' => $worker->id, 'date' => '2026-05-03', 'quantity' => '150']);
    setStatus($m, MeasurementStatus::Approved);
    ProductionTask::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'completed_quantity' => 999, 'unit_price' => 100]);

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-05');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $worker->id)->firstOrFail();

    expect((float) $payroll->getAttribute('gross_pay'))->toBe(250.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(250.0);
});
