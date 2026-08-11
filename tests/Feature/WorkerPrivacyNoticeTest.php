<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\WorkerPrivacyNotice;
use Illuminate\Http\UploadedFile;

/**
 * The geolocation + selfie privacy notice gate.
 *
 * Spanish law makes informing the worker BEFORE any monitoring a duty, so a
 * fresh worker must see the notice before their first punch, the punch must be
 * refused server-side until they acknowledge it, and the acknowledgement must
 * leave an audit trail (see docs/GDPR_WORKER_NOTICE.md).
 */
beforeEach(function (): void {
    // Weekends need a work offer for check-in; pin to a Monday for the notice flow.
    $this->travelTo('2026-08-10 09:00');
    $this->company = Company::factory()->create();
});

afterEach(fn () => $this->travelBack());

/** A worker login linked to an employee, NOT yet having seen the notice. */
function unacknowledgedWorker(Company $company): array
{
    $user = User::factory()->create([
        'role' => UserRole::Worker,
        'company_id' => $company->id,
        'password' => 'password',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);
    $employee->user_id = $user->id;
    $employee->save();

    return [$user, $employee];
}

it('tells the home screen a fresh worker has not acknowledged the notice', function (): void {
    [$user] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->get('/worker')
        ->assertInertia(fn ($page) => $page->where('privacy_acknowledged', false));
});

it('refuses a check-in until the notice is acknowledged', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/check-in', ['denied' => true])->assertForbidden();

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('refuses a check-out until the notice is acknowledged', function (): void {
    [$user] = unacknowledgedWorker($this->company);

    // Attachment supplied so the request validates and reaches the privacy gate.
    $this->actingAs($user)->post('/worker/check-out', ['denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg')])->assertForbidden();
});

it('records the acknowledgement, with the version, and audits it', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/privacy-ack')->assertRedirect();

    $employee->refresh();
    expect($employee->privacy_notice_ack_at)->not->toBeNull()
        ->and($employee->privacy_notice_ack_version)->toBe(WorkerPrivacyNotice::VERSION)
        ->and($employee->hasAcknowledgedPrivacyNotice())->toBeTrue();

    // The write is the evidence the employer met its information duty.
    expect(AuditLog::where('model_type', (new Employee)->getMorphClass())
        ->where('model_id', (string) $employee->id)
        ->where('action', 'updated')
        ->exists())->toBeTrue();
});

it('lets the worker punch once the notice is acknowledged', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/privacy-ack');
    $this->actingAs($user)->post('/worker/check-in', ['denied' => true])->assertRedirect();

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

it('re-gates a worker whose acknowledged version is behind the current one', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    // They acknowledged an OLDER notice; a material change bumped the version.
    $employee->forceFill([
        'privacy_notice_ack_at' => now()->subMonth(),
        'privacy_notice_ack_version' => WorkerPrivacyNotice::VERSION - 1,
    ])->save();

    expect($employee->hasAcknowledgedPrivacyNotice())->toBeFalse();

    $this->actingAs($user)->post('/worker/check-in', ['denied' => true])->assertForbidden();
});
