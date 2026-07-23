<?php

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LockedPeriod;
use App\Models\User;
use App\Services\Workers\WorkerAccountService;
use App\Support\PeriodLock;
use Illuminate\Http\UploadedFile;
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

it('refuses a second check-in on the same day', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true]);

    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true])
        ->assertSessionHasErrors('check_in');

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('computes hours and pay on check-out from the frozen snapshot', function (): void {
    // Check in, then travel the clock forward and check out.
    $this->actingAs($this->worker)->post('/worker/check-in', ['denied' => true]);

    $this->travel(8)->hours();

    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true])->assertRedirect();

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

it('refuses check-out without a check-in', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-out', ['denied' => true])
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
