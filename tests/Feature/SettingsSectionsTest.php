<?php

use App\Console\Commands\AutoAbsentCommand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Settings\SettingsService;
use App\Support\AttendanceAbsence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create(['name' => 'Original SL']);
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

// --- 5B: Company profile ---------------------------------------------------

it('ships the company profile to the Settings page', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('companyProfile.name', 'Original SL'));
});

it('updates company profile and stores an uploaded logo on the private disk', function (): void {
    Storage::fake('local');

    $this->actingAs($this->admin)->post('/admin/settings/company-profile', [
        'name' => 'Nuevo Nombre SL',
        'cif' => 'B12345678',
        'address' => 'Calle Falsa 123',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
    ])->assertRedirect();

    $company = $this->company->fresh();
    expect($company->name)->toBe('Nuevo Nombre SL')
        ->and($company->cif)->toBe('B12345678')
        ->and($company->address)->toBe('Calle Falsa 123')
        ->and($company->logo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($company->logo_path);
});

it('denies company profile update to a non-admin', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->post('/admin/settings/company-profile', ['name' => 'X'])
        ->assertForbidden();
});

// --- 5A: Working days ------------------------------------------------------

it('defaults working days to Monday–Friday', function (): void {
    expect(app(AttendanceService::class)->workingDays($this->company->id))->toBe([1, 2, 3, 4, 5]);
});

it('saves the company working days', function (): void {
    $this->actingAs($this->admin)->put('/admin/settings/working-days', [
        'working_days' => [1, 2, 3, 4, 5, 6],
    ])->assertRedirect();

    expect(app(AttendanceService::class)->workingDays($this->company->id))->toBe([1, 2, 3, 4, 5, 6]);
});

it('rejects empty or invalid working days', function (): void {
    $this->actingAs($this->admin)->put('/admin/settings/working-days', ['working_days' => []])
        ->assertSessionHasErrors('working_days');

    $this->actingAs($this->admin)->put('/admin/settings/working-days', ['working_days' => [8]])
        ->assertSessionHasErrors('working_days.0');
});

it('honours working days in the computed absence rule', function (): void {
    $sat = Carbon::parse('2026-05-16'); // a Saturday
    $today = Carbon::parse('2026-05-20');
    $joining = Carbon::parse('2026-05-01');

    // Default Mon–Fri: Saturday is off → not an absence.
    expect(AttendanceAbsence::isUnrecordedAbsence($sat, $today, $joining, true, null, [1, 2, 3, 4, 5]))->toBeFalse();
    // Saturday declared a working day → it IS an absence.
    expect(AttendanceAbsence::isUnrecordedAbsence($sat, $today, $joining, true, null, [1, 2, 3, 4, 5, 6]))->toBeTrue();
});

it('auto-absent respects per-company working days on a weekend', function (): void {
    // Company works Saturdays; its worker with no record on a past Saturday is
    // auto-absent. A default (Mon–Fri) company's worker is not.
    app(SettingsService::class)->set("attendance.working_days.{$this->company->id}", [1, 2, 3, 4, 5, 6]);
    $satWorker = Employee::factory()->forCompany($this->company)->create([
        'active' => true, 'joining_date' => '2026-05-01',
    ]);

    $default = Company::factory()->create();
    $mfWorker = Employee::factory()->forCompany($default)->create([
        'active' => true, 'joining_date' => '2026-05-01',
    ]);

    $this->artisan(AutoAbsentCommand::class, ['--date' => '2026-05-16']) // Saturday
        ->assertSuccessful();

    $this->assertDatabaseHas('attendance', [
        'employee_id' => $satWorker->id, 'date' => '2026-05-16', 'status' => 'absent',
    ]);
    $this->assertDatabaseMissing('attendance', [
        'employee_id' => $mfWorker->id, 'date' => '2026-05-16',
    ]);
});
