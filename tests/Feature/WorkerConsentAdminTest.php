<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkerConsent;
use App\Services\Settings\SettingsService;

/**
 * The admin side of worker privacy consent (GDPR legal evidence): the Employee
 * Detail record, the force-re-accept reset, the per-record PDF, and the
 * brand-wide version bump.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->worker = User::factory()->create(['role' => 'worker', 'company_id' => $this->company->id]);
    $this->employee = Employee::factory()->forCompany($this->company)
        ->privacyAcknowledged(gps: true, photo: false)
        ->create(['user_id' => $this->worker->id, 'full_name' => 'Obrero Uno']);
});

it('shows the consent record on the Employee Detail page', function (): void {
    $this->actingAs($this->admin)->get("/employees/{$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('consent.accepted', true)
            ->where('consent.active.attendance', true)
            ->where('consent.active.gps', true)
            ->where('consent.active.photo', false)
            ->has('consent.active.ip_address')
            ->has('consent.active.consented_at'));
});

it('lets an admin reset consent, forcing the worker to re-accept', function (): void {
    $this->actingAs($this->admin)->post("/employees/{$this->employee->id}/consent/reset")->assertRedirect();

    expect($this->employee->fresh()->hasAcknowledgedPrivacyNotice())->toBeFalse()
        ->and(WorkerConsent::where('employee_id', $this->employee->id)->whereNotNull('revoked_at')->exists())->toBeTrue();
});

it('generates a consent PDF for an attendance viewer', function (): void {
    $consent = WorkerConsent::query()->where('employee_id', $this->employee->id)->firstOrFail();

    $response = $this->actingAs($this->admin)->get("/employees/{$this->employee->id}/consent/{$consent->id}/pdf");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('cannot download a consent record from another employee', function (): void {
    $otherEmployee = Employee::factory()->forCompany($this->company)->create();
    $consent = WorkerConsent::query()->where('employee_id', $this->employee->id)->firstOrFail();

    // The consent id belongs to $this->employee, not $otherEmployee → 404.
    $this->actingAs($this->admin)->get("/employees/{$otherEmployee->id}/consent/{$consent->id}/pdf")->assertNotFound();
});

it('bumps the consent version from Settings and forces everyone to re-accept', function (): void {
    expect($this->employee->hasAcknowledgedPrivacyNotice())->toBeTrue();

    $this->actingAs($this->admin)->put('/admin/settings/legal', ['consent_version' => 'v2.0-2026-09'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(app(SettingsService::class)->get('legal.consent_version'))->toBe('v2.0-2026-09')
        ->and($this->employee->fresh()->hasAcknowledgedPrivacyNotice())->toBeFalse();
});
