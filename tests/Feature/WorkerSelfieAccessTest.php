<?php

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The check-in selfie is a photo of a person on a private disk. It is served
 * only through the gated, audited route (Phase E) — permission-checked,
 * tenant-scoped, and logged on every view.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

function attendanceWithSelfie(Company $company, Employee $employee): Attendance
{
    Storage::fake('local');
    $path = UploadedFile::fake()->image('selfie.jpg')->store("attendance-selfies/{$company->id}/{$employee->id}", 'local');

    $row = new Attendance([
        'employee_id' => $employee->id, 'date' => now()->toDateString(),
        'mode' => 'hourly', 'status' => 'present',
    ]);
    $row->company_id = $company->id;
    $row->check_in_photo_path = $path;
    $row->source = 'worker';
    $row->save();

    return $row;
}

it('serves the selfie to an attendance viewer and audits it', function (): void {
    $row = attendanceWithSelfie($this->company, $this->employee);

    $this->actingAs($this->admin)->get("/attendance/{$row->id}/selfie")->assertOk();

    expect(AuditLog::query()->where('action', 'viewed')->where('module', 'attendance')->exists())->toBeTrue();
});

it('denies the selfie without attendance.view', function (): void {
    $row = attendanceWithSelfie($this->company, $this->employee);

    // A custom user with no attendance grant.
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get("/attendance/{$row->id}/selfie")->assertForbidden();
});

it('allows the selfie for a user granted attendance.view', function (): void {
    $row = attendanceWithSelfie($this->company, $this->employee);

    $user = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'attendance', 'can_view' => true,
    ]);

    $this->actingAs($user)->get("/attendance/{$row->id}/selfie")->assertOk();
});

it('cannot reach another company attendance selfie', function (): void {
    $other = Company::factory()->create();
    $foreignEmp = Employee::factory()->forCompany($other)->create();
    $foreign = attendanceWithSelfie($other, $foreignEmp);

    // The tenant scope on the route binding 404s a cross-company id.
    $this->actingAs($this->admin)->get("/attendance/{$foreign->id}/selfie")->assertNotFound();
});

it('404s when the row has no selfie', function (): void {
    $row = new Attendance([
        'employee_id' => $this->employee->id, 'date' => now()->toDateString(),
        'mode' => 'hourly', 'status' => 'present',
    ]);
    $row->company_id = $this->company->id;
    $row->save();

    $this->actingAs($this->admin)->get("/attendance/{$row->id}/selfie")->assertNotFound();
});
