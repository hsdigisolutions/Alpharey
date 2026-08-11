<?php

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LockedPeriod;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Workers\WorkerAccountService;
use App\Support\PeriodLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A worker checking in / out / reporting an absence from the phone.
 *
 * The wage side is delegated to AttendanceService, so these tests focus on the
 * worker-specific promises: one punch per day, GPS + selfie captured on the
 * row, a refused location flagged rather than blocking, and an absence carrying
 * the worker's own reason through to the CRM.
 */
beforeEach(function (): void {
    // Check-out stores a proof-of-work attachment on the local disk — fake it.
    Storage::fake('local');

    // Weekends are days off (a check-in needs a weekend offer), so pin the clock
    // to a weekday for the ordinary punch-flow tests. 2026-08-10 is a Monday.
    $this->travelTo('2026-08-10 09:00');

    $this->company = Company::factory()->create();
    // Pre-acknowledged: the notice gate has its own test (WorkerPrivacyNoticeTest);
    // here we exercise the punch flow, which sits behind an acknowledged notice.
    $this->employee = Employee::factory()->forCompany($this->company)->privacyAcknowledged()->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);

    // Give the employee a worker login and sign in as them.
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($admin);
    app(WorkerAccountService::class)->grant($this->employee, 'obrero@example.com', 'site-pass-123');
    auth()->logout();

    $this->worker = $this->employee->fresh()->user;
});

afterEach(fn () => $this->travelBack());

it('records a check-in with GPS and a selfie', function (): void {
    Storage::fake('local');

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => 40.4168,
        'lng' => -3.7038,
        'accuracy' => 12.5,
        'denied' => false,
        'photo' => UploadedFile::fake()->image('selfie.jpg'),
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();

    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe('worker')
        ->and((float) $row->check_in_lat)->toBe(40.4168)
        ->and($row->location_denied)->toBeFalse()
        ->and($row->check_in_photo_path)->not->toBeNull()
        ->and($row->check_in_at)->not->toBeNull();

    Storage::disk('local')->assertExists($row->check_in_photo_path);
});

it('records the punch but flags it when location is denied', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'denied' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();

    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->location_denied)->toBeTrue()
        ->and($row->check_in_lat)->toBeNull();
});

it('notifies the company admins when a worker checks in without GPS', function (): void {
    Notification::fake();

    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])->assertRedirect();

    // The company admin (created in beforeEach) is a recipient of the
    // worker_gps_missing type by its coded default (Admin role).
    Notification::assertSentTo(
        User::where('role', 'admin')->where('company_id', $this->company->id)->get(),
        SystemNotification::class,
    );
});

it('notifies admins about a short shift under the half-day threshold', function (): void {
    Notification::fake();

    // Check in at 09:00 (beforeEach clock), then check out at 10:30 — 1.5 h,
    // under the default 3 h half-day threshold → a short-shift alert.
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])->assertRedirect();

    $this->travelTo('2026-08-10 10:30');
    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg')])->assertRedirect();

    Notification::assertSentTo(
        User::where('role', 'admin')->where('company_id', $this->company->id)->get(),
        SystemNotification::class,
        fn (SystemNotification $n) => ($n->toDatabase($this->worker)['type'] ?? null) === 'short_hours',
    );
});

it('does not raise a short-shift alert for a full-length day', function (): void {
    Notification::fake();

    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])->assertRedirect();

    // Check out after 8 h — well over the half-day threshold.
    $this->travelTo('2026-08-10 17:00');
    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg')])->assertRedirect();

    Notification::assertNotSentTo(
        User::where('role', 'admin')->where('company_id', $this->company->id)->get(),
        SystemNotification::class,
        fn (SystemNotification $n) => ($n->toDatabase($this->worker)['type'] ?? null) === 'short_hours',
    );
});

it('does not raise the GPS alert when a location is captured', function (): void {
    Notification::fake();

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => 40.4168, 'lng' => -3.7038, 'accuracy' => 10, 'denied' => false,
    ])->assertRedirect();

    Notification::assertNothingSent();
});

it('does NOT flag a location mismatch when the check-in GPS was inaccurate (the bug fix)', function (): void {
    Notification::fake();
    // Check in with a garbage ±50 km fix (IP-based), far from the check-out.
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => 29.88, 'lng' => 71.77, 'accuracy' => 50000, 'denied' => false,
    ])->assertRedirect();

    $this->travelTo('2026-08-10 12:00');
    $this->actingAs($this->worker)->post('/worker/check-out', [
        'lat' => 30.07, 'lng' => 71.16, 'accuracy' => 100, 'denied' => false,
        'work_attachment' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    // The guard skips the comparison entirely, so the flag is never set (null).
    expect($row->location_mismatch)->not->toBeTrue();
    Notification::assertNotSentTo(
        User::where('role', 'admin')->where('company_id', $this->company->id)->get(),
        SystemNotification::class,
        fn (SystemNotification $n) => ($n->toDatabase($this->worker)['type'] ?? null) === 'worker_location_mismatch',
    );
});

it('flags a mismatch and alerts admins when an accurate check-in is far from check-out', function (): void {
    Notification::fake();
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => 40.4, 'lng' => -3.7, 'accuracy' => 25, 'denied' => false,
    ])->assertRedirect();

    $this->travelTo('2026-08-10 12:00');
    $this->actingAs($this->worker)->post('/worker/check-out', [
        'lat' => 41.0, 'lng' => -4.0, 'accuracy' => 30, 'denied' => false,
        'work_attachment' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->location_mismatch)->toBeTrue();
    Notification::assertSentTo(
        User::where('role', 'admin')->where('company_id', $this->company->id)->get(),
        SystemNotification::class,
        fn (SystemNotification $n) => ($n->toDatabase($this->worker)['type'] ?? null) === 'worker_location_mismatch',
    );
});

it('does not flag a mismatch when check-out is close to an accurate check-in', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => 40.4, 'lng' => -3.7, 'accuracy' => 20, 'denied' => false,
    ])->assertRedirect();

    $this->travelTo('2026-08-10 12:00');
    $this->actingAs($this->worker)->post('/worker/check-out', [
        'lat' => 40.4001, 'lng' => -3.7001, 'accuracy' => 20, 'denied' => false,
        'work_attachment' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->location_mismatch)->toBeFalse();
});

it('refuses a second check-in on the same day', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true]);

    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])
        ->assertSessionHasErrors('check_in');

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('computes hours and pay on check-out from the frozen snapshot', function (): void {
    // Anchor to a fixed morning hour so the +8h below never crosses midnight —
    // otherwise, run late enough in the day, check-out lands on the NEXT date
    // and finds no row to close (a real cross-midnight limitation, but not what
    // this test is about). travelBack() at the end restores the real clock.
    $this->travelTo(now()->startOfDay()->addHours(8));

    // Check in, then travel the clock forward and check out.
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true]);

    $this->travel(8)->hours();

    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg')])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();

    // 8h elapsed − 1h unpaid break (the default, same as the clerk grid) = 7h
    // × 20/h = 140 €. This confirms the worker punch flows through the identical
    // wage path, break deduction included.
    expect((float) $row->hours_worked)->toBeGreaterThanOrEqual(6.9)
        ->and((float) $row->hours_worked)->toBeLessThanOrEqual(7.1)
        ->and((float) $row->total_amount)->toBeGreaterThanOrEqual(138.0)
        ->and($row->check_out_at)->not->toBeNull();

    $this->travelBack();
});

it('requires a proof-of-work attachment to check out', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])->assertRedirect();
    $this->travelTo('2026-08-10 17:00');

    // No work_attachment → validation refuses it and the day stays open.
    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true])
        ->assertSessionHasErrors('work_attachment');

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->check_out)->toBeNull();
});

it('stores the proof-of-work attachment on check-out', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])->assertRedirect();
    $this->travelTo('2026-08-10 17:00');

    $this->actingAs($this->worker)->post('/worker/check-out', [
        'denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->check_out)->not->toBeNull()
        ->and($row->check_out_attachment_path)->not->toBeNull()
        ->and($row->check_out_attachment_name)->toBe('site.jpg');
    Storage::disk('local')->assertExists($row->check_out_attachment_path);
});

it('serves the check-out attachment to an admin and audits it', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])->assertRedirect();
    $this->travelTo('2026-08-10 17:00');
    $this->actingAs($this->worker)->post('/worker/check-out', [
        'denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();
    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();

    $admin = User::where('role', 'admin')->where('company_id', $this->company->id)->firstOrFail();
    $this->actingAs($admin)->get("/attendance/{$row->id}/checkout-attachment")->assertOk();

    expect(AuditLog::where('action', 'viewed')->where('description', 'Check-out attachment')->exists())->toBeTrue();
});

it('refuses check-out without a check-in', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true, 'work_attachment' => UploadedFile::fake()->image('site.jpg')])
        ->assertSessionHasErrors('check_out');
});

it('records an absence with the worker\'s reason for the CRM', function (): void {
    $this->actingAs($this->worker)->post('/worker/absence', [
        'note' => 'Cita médica en el centro de salud',
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();

    expect($row->status)->toBe(AttendanceStatus::Absent)
        ->and($row->worker_note)->toBe('Cita médica en el centro de salud')
        ->and((float) $row->total_amount)->toBe(0.0)
        ->and($row->source)->toBe('worker');
});

it('will not report an absence once already checked in', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true]);

    $this->actingAs($this->worker)->post('/worker/absence', ['note' => 'changed my mind'])
        ->assertSessionHasErrors('note');
});

it('rejects a punch into a locked month', function (): void {
    $period = new LockedPeriod(['month' => now()->format('Y-m'), 'locked_at' => now()]);
    $period->company_id = $this->company->id;
    $period->save();
    app(PeriodLock::class)->forget();

    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])
        ->assertSessionHasErrors('check_in');

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

it('does not let a normal CRM user reach the worker endpoints', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->post('/worker/check-in', ['denied' => true])->assertForbidden();
    $this->actingAs($user)->get('/worker')->assertForbidden();
});
