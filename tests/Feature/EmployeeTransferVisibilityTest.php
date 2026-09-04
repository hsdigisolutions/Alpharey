<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * BUG 1 (audit) — under the single-record transfer model a worker's record has
 * its company_id flipped to the NEW company, so the OLD company lost sight of
 * them entirely (the list is tenant-scoped, and the old "transferred_out_at"
 * filter never matched). These pin the fix: the old company sees them under the
 * 'transferred' filter, opens them READ-ONLY without a 404, and never sees the
 * new company's data — while the new company sees them as a normal active worker.
 */
beforeEach(function (): void {
    $this->companyA = Company::factory()->create(['name' => 'Alpha']);
    $this->companyB = Company::factory()->create(['name' => 'Beta']);
    $this->companyC = Company::factory()->create(['name' => 'Gamma']);
    $this->adminA = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->companyA->id]);
    $this->adminB = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->companyB->id]);
    $this->adminC = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->companyC->id]);
    $this->sa = User::factory()->superAdmin()->create();

    $this->employee = Employee::factory()->forCompany($this->companyA)->create([
        'full_name' => 'Habib Test', 'wage_type' => 'daily', 'daily_wage' => '50', 'joining_date' => '2026-01-01',
    ]);
    // An A-era attendance row (before the transfer).
    Attendance::factory()->create([
        'company_id' => $this->companyA->id, 'employee_id' => $this->employee->id,
        'date' => '2026-08-05', 'status' => 'present',
    ]);

    // Transfer A → B (as Super Admin).
    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    // A B-era attendance row (after the transfer, logged under company B).
    Attendance::factory()->create([
        'company_id' => $this->companyB->id, 'employee_id' => $this->employee->id,
        'date' => '2026-09-05', 'status' => 'present',
    ]);
});

it('shows the transferred-away worker under the OLD company transferred filter, not the active list', function (): void {
    $this->actingAs($this->adminA);

    // Default (active) list — NOT there.
    $this->get('/employees')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('employees.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($this->employee->id)));

    // Transferred filter — IS there, flagged transferred_away, company = Beta.
    $this->get('/employees?status=transferred')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('employees.data', function ($rows) {
            $row = collect($rows)->firstWhere('id', $this->employee->id);

            return $row !== null && $row['transferred_away'] === true && $row['company'] === 'Beta';
        }));
});

it('still shows the worker as a normal active employee at the NEW company', function (): void {
    $this->actingAs($this->adminB)
        ->get('/employees')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('employees.data', fn ($rows) => collect($rows)->pluck('id')->contains($this->employee->id)));
});

it('lets the OLD company open the worker READ-ONLY without a 404, with a transfer banner', function (): void {
    $this->actingAs($this->adminA)
        ->get("/employees/{$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Employees/Detail')
            ->where('readOnly', true)
            ->where('can.edit', false)
            ->where('can.delete', false)
            ->where('can.transfer', false)
            ->where('transferBanner.transferred_to', 'Beta')
            ->where('transferBanner.on', '2026-08-17'));
});

it('blocks writes to a transferred-away worker from the OLD company (404, not just UI)', function (): void {
    $this->actingAs($this->adminA)
        ->put("/employees/{$this->employee->id}", ['full_name' => 'Hacked'])
        ->assertNotFound();

    expect($this->employee->fresh()->full_name)->toBe('Habib Test');
});

it('never leaks the NEW company attendance into the OLD company read-only view', function (): void {
    $this->actingAs($this->adminA);

    // A-era month: the A row is present.
    $this->get("/employees/{$this->employee->id}?att_month=2026-08")
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendanceTab.grid', fn ($grid) => collect($grid)->isNotEmpty()));

    // B-era month: the B row must NOT appear, and no fabricated absences either.
    $this->get("/employees/{$this->employee->id}?att_month=2026-09")
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendanceTab.grid', fn ($grid) => collect($grid)->isEmpty()));
});

it('still 404s for an unrelated company with no stint here', function (): void {
    $this->actingAs($this->adminC)
        ->get("/employees/{$this->employee->id}")
        ->assertNotFound();
});
