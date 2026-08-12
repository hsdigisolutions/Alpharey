<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkerConsent;
use App\Notifications\SystemNotification;
use App\Services\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The worker privacy CONSENT gate (GDPR art. 7 & 13, LOPDGDD 3/2018,
 * RD-ley 8/2019). A fresh worker must accept the notice before any punch; the
 * acceptance is legal evidence (exact text, IP, user-agent, version, timestamp);
 * attendance is mandatory (acknowledged) while GPS + selfie are optional consents
 * the worker can withhold or revoke — the app works without them.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo('2026-08-10 09:00'); // a Monday
    $this->company = Company::factory()->create();
});

afterEach(fn () => $this->travelBack());

/** A worker login linked to an employee, NOT yet having accepted the notice. */
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

it('tells the home screen a fresh worker has not accepted the notice', function (): void {
    [$user] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->get('/worker')
        ->assertInertia(fn ($page) => $page->where('privacy_acknowledged', false));
});

it('refuses a check-in until the notice is accepted', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/check-in', ['denied' => true])->assertForbidden();

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('refuses a check-out until the notice is accepted', function (): void {
    [$user] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/check-out', ['denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg')])->assertForbidden();
});

it('records the full consent record as legal evidence', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/privacy-ack', [
        'consent_attendance' => true,
        'consent_gps' => true,
        'consent_photo' => false,
    ])->assertRedirect();

    $consent = WorkerConsent::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($consent->consent_attendance)->toBeTrue()
        ->and($consent->consent_gps)->toBeTrue()
        ->and($consent->consent_photo)->toBeFalse()
        ->and($consent->consent_version)->not->toBeEmpty()
        ->and($consent->ip_address)->not->toBeNull()
        ->and($consent->consented_at)->not->toBeNull()
        ->and($consent->timezone)->toBe('Europe/Madrid')
        ->and($consent->language)->toBeIn(['es', 'en'])
        ->and($consent->consent_text_shown)->not->toBeEmpty()
        ->and($consent->user_id)->toBe($user->id)
        ->and($employee->hasAcknowledgedPrivacyNotice())->toBeTrue();
});

it('refuses acceptance without the mandatory attendance acknowledgement', function (): void {
    [$user] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/privacy-ack', [
        'consent_attendance' => false, 'consent_gps' => true, 'consent_photo' => true,
    ])->assertSessionHasErrors('consent_attendance');
});

it('lets the worker punch once the notice is accepted', function (): void {
    [$user, $employee] = unacknowledgedWorker($this->company);

    $this->actingAs($user)->post('/worker/privacy-ack', ['consent_attendance' => true]);
    $this->actingAs($user)->post('/worker/check-in', ['denied' => true])->assertRedirect();

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

it('re-gates a worker whose accepted version is behind the current one', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->privacyAcknowledged()->create();

    expect($employee->hasAcknowledgedPrivacyNotice())->toBeTrue();

    // A policy change bumps the version → the old acceptance no longer counts.
    app(SettingsService::class)->set('legal.consent_version', 'v2.0-2026-09');

    expect($employee->fresh()->hasAcknowledgedPrivacyNotice())->toBeFalse();
});

it('skips GPS capture and the GPS-missing alert when GPS consent is withheld', function (): void {
    Notification::fake();
    $user = User::factory()->create(['role' => UserRole::Worker, 'company_id' => $this->company->id]);
    User::factory()->companyAdmin()->forCompany($this->company)->create();
    $employee = Employee::factory()->forCompany($this->company)->privacyAcknowledged(gps: false)->create(['user_id' => $user->id]);

    // Even if coordinates are POSTed, no GPS consent → none stored, no alert.
    $this->actingAs($user)->post('/worker/check-in', ['lat' => 40.4, 'lng' => -3.7, 'accuracy' => 10, 'denied' => false])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect($row->check_in_lat)->toBeNull();
    Notification::assertNotSentTo(
        User::where('role', 'admin')->where('company_id', $this->company->id)->get(),
        SystemNotification::class,
        fn (SystemNotification $n) => ($n->toDatabase($user)['type'] ?? null) === 'worker_gps_missing',
    );
});

it('skips the selfie when photo consent is withheld', function (): void {
    $user = User::factory()->create(['role' => UserRole::Worker, 'company_id' => $this->company->id]);
    $employee = Employee::factory()->forCompany($this->company)->privacyAcknowledged(photo: false)->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/worker/check-in', [
        'denied' => true, 'photo' => UploadedFile::fake()->image('selfie.jpg'),
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect($row->check_in_photo_path)->toBeNull();
});

it('lets the worker revoke GPS consent, writing a new record and revoking the old', function (): void {
    $user = User::factory()->create(['role' => UserRole::Worker, 'company_id' => $this->company->id]);
    $employee = Employee::factory()->forCompany($this->company)->privacyAcknowledged()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/worker/consent', ['consent_gps' => false, 'consent_photo' => true])->assertRedirect();

    expect($employee->consentGps())->toBeFalse()
        ->and($employee->consentPhoto())->toBeTrue()
        // The previous record is kept but revoked — history is never destroyed.
        ->and(WorkerConsent::where('employee_id', $employee->id)->whereNotNull('revoked_at')->exists())->toBeTrue()
        ->and(WorkerConsent::where('employee_id', $employee->id)->count())->toBe(2);
});
