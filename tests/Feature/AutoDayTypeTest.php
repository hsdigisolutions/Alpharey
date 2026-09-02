<?php

use App\Enums\DayType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Settings\SettingsService;

/**
 * Automatic day-type detection: the system grades a worked day from the hours,
 * on check-out, using per-company thresholds. The worker never picks; an admin
 * can override (which flips is_auto_detected off).
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->service = app(AttendanceService::class);
});

it('grades hours into full / half / hourly by the default thresholds', function (): void {
    expect($this->service->autoDayType(7, $this->company->id))->toBe(DayType::Full)   // >= 6
        ->and($this->service->autoDayType(4, $this->company->id))->toBe(DayType::Half) // >= 3
        ->and($this->service->autoDayType(2.5, $this->company->id))->toBe(DayType::Hourly); // < 3
});

it('respects per-company thresholds from settings', function (): void {
    app(SettingsService::class)->set("attendance.full_day_threshold.{$this->company->id}", 8);
    app(SettingsService::class)->set("attendance.half_day_threshold.{$this->company->id}", 4);

    expect($this->service->autoDayType(7, $this->company->id))->toBe(DayType::Half)   // 4 <= 7 < 8
        ->and($this->service->autoDayType(9, $this->company->id))->toBe(DayType::Full)
        ->and($this->service->autoDayType(3, $this->company->id))->toBe(DayType::Hourly); // < 4
});

it('auto-detects a full day for a daily worker on check-out', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80', 'wage_rate' => '10',
    ]);
    // A weekday row with 7 worked hours (grading prices from the daily rate).
    $att = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-10', 'status' => 'present', 'day_type' => 'hourly', 'hours_worked' => '7',
    ]);

    $this->service->applyAutoDayType($att->fresh(), $employee);
    $att->refresh();

    expect($att->day_type)->toBe(DayType::Full)
        ->and($att->auto_day_type)->toBe(DayType::Full)
        ->and($att->is_auto_detected)->toBeTrue()
        ->and((float) $att->total_amount)->toBe(80.0); // daily rate × 1.0
});

it('keeps a purely hourly worker on hourly even past the full-day threshold', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '10', 'daily_wage' => null,
    ]);
    $att = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-10', 'status' => 'present', 'day_type' => 'hourly', 'hours_worked' => '8',
    ]);

    $this->service->applyAutoDayType($att->fresh(), $employee);
    $att->refresh();

    // No daily rate → stays hourly and is priced hourly (a "full day" it could
    // not price would be 0). day_type is the load-bearing assertion here.
    expect($att->day_type)->toBe(DayType::Hourly)
        ->and((float) $att->total_amount)->toBeGreaterThan(0.0);
});

it('grades a weekend day too (offered work is a full/half day, not hours × hourly)', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
    // 2026-08-08 is a Saturday — a worker only reaches here via an invited offer,
    // so a full day is graded like a weekday (the weekend premium rides on top).
    $att = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-08', 'status' => 'present', 'day_type' => 'hourly', 'hours_worked' => '7',
    ]);

    $this->service->applyAutoDayType($att->fresh(), $employee);
    $att->refresh();

    expect($att->is_auto_detected)->toBeTrue()
        ->and($att->day_type)->toBe(DayType::Full)
        ->and((float) $att->total_amount)->toBe(80.0); // daily rate, no premium set
});

it('marks an admin day-type edit as a manual override', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
    $att = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-10', 'status' => 'present', 'day_type' => 'full', 'hours_worked' => '7',
    ]);
    // Pretend the system had auto-detected it.
    $att->forceFill(['auto_day_type' => 'full', 'is_auto_detected' => true])->save();

    $this->actingAs($admin);
    $this->service->update($att, [
        'employee_id' => $employee->id, 'date' => '2026-08-10',
        'mode' => 'project_based', 'day_type' => 'half', 'status' => 'present',
    ]);
    $att->refresh();

    expect($att->is_auto_detected)->toBeFalse()      // admin override
        ->and($att->day_type)->toBe(DayType::Half)
        ->and($att->auto_day_type)->toBe(DayType::Full); // detection record kept
});

it('resolves the per-company break from settings for displayHoursNet when no break is passed', function (): void {
    // Step 2: the model helper reads break_duration_minutes through the service
    // when the caller does not supply it. 45 min off an 08:00–17:00 full day.
    app(SettingsService::class)->set("attendance.break_duration_minutes.{$this->company->id}", 45);

    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
    // A clerk full day (no recorded hours) so the span-net path runs; the
    // factory default hours_worked is cleared to 0.
    $att = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-10', 'status' => 'present', 'day_type' => 'full',
        'check_in' => '08:00', 'check_out' => '17:00', 'hours_worked' => '0',
    ]);

    expect($att->displayHoursNet())->toBe(8.25); // 9 h − 0.75 h break
});
