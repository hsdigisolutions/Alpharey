<?php

use App\Enums\AdvanceStatus;
use App\Enums\WageType;
use App\Enums\WorkerExpenseStatus;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\AttendanceVoiceNote;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSession;
use App\Models\WorkerExpense;
use App\Services\Payroll\PayrollService;
use App\Services\Workers\VehicleSessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function workerWithEmployee(array $employeeOverrides = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->worker()->for($company)->create();
    $employee = Employee::factory()
        ->for($company)
        ->create(array_merge(['user_id' => $user->id, 'wage_type' => WageType::Daily], $employeeOverrides));

    return [$user, $employee, $company];
}

it('adds a worker expense from the CRM — payroll counts it only after FINAL approval', function () {
    [, $employee, $company] = workerWithEmployee(['daily_wage' => '50']);
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();

    $this->actingAs($admin)->post('/worker-expenses', [
        'employee_id' => $employee->id, 'date' => '2026-05-04',
        'amount' => '25', 'category' => 'fuel', 'description' => 'Diesel',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $expense = WorkerExpense::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect($expense->status)->toBe(WorkerExpenseStatus::Approved)
        ->and($expense->company_id)->toBe($company->id);

    // Manager-level approval created the mirror Expense, but it is NOT yet
    // approved → payroll must NOT count it (the whole point of the two-gate flow).
    $mirror = Expense::withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();
    $before = app(PayrollService::class)->calculateFor($employee, $company->id, '2026-05');
    expect((float) $before->getAttribute('reimbursements'))->toBe(0.0);

    // Admin FINAL approval in the Expenses tab releases the money into payroll.
    // (Re-use the same payroll row so we recompute rather than insert a second.)
    $this->actingAs($admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true])->assertRedirect();
    $after = app(PayrollService::class)->calculateFor($employee, $company->id, '2026-05', $before);
    expect((float) $after->getAttribute('reimbursements'))->toBe(25.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature 1 — Voice note
// ─────────────────────────────────────────────────────────────────────────────

describe('Feature 1 – voice note', function () {
    it('stores a text-only note on a checked-out attendance record', function () {
        Storage::fake('local');
        [$user, $employee] = workerWithEmployee();

        $attendance = Attendance::factory()->for($employee)->for($employee->company)->create([
            'check_in' => now()->startOfDay()->addHours(8),
            'check_out' => now()->startOfDay()->addHours(16),
        ]);

        $this->actingAs($user)
            ->post('/worker/voice-note', [
                'attendance_id' => $attendance->id,
                'text_note' => 'Todo bien en la obra.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance_voice_notes', [
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'text_note' => 'Todo bien en la obra.',
        ]);
    });

    it('stores an audio note', function () {
        Storage::fake('local');
        [$user, $employee] = workerWithEmployee();

        $attendance = Attendance::factory()->for($employee)->for($employee->company)->create([
            'check_in' => now()->startOfDay()->addHours(8),
            'check_out' => now()->startOfDay()->addHours(16),
        ]);

        $audio = UploadedFile::fake()->create('note.webm', 50, 'audio/webm');

        $this->actingAs($user)
            ->post('/worker/voice-note', [
                'attendance_id' => $attendance->id,
                'audio' => $audio,
                'duration_seconds' => 12,
            ])
            ->assertRedirect();

        // Bypass CompanyScope — workers have no CRM session so the scope filters to null.
        $note = AttendanceVoiceNote::withoutGlobalScopes()->where('attendance_id', $attendance->id)->firstOrFail();
        expect($note->audio_path)->not->toBeNull();
        expect($note->duration_seconds)->toBe(12);
    });

    it('rejects a note on another worker attendance', function () {
        Storage::fake('local');
        [$user] = workerWithEmployee();
        [, $otherEmployee] = workerWithEmployee();

        $attendance = Attendance::factory()->for($otherEmployee)->for($otherEmployee->company)->create([
            'check_in' => now()->startOfDay()->addHours(8),
            'check_out' => now()->startOfDay()->addHours(16),
        ]);

        // The controller filters by employee_id, so another worker's attendance is
        // not found for the acting employee → 404 (leaks less than 403).
        $this->actingAs($user)
            ->post('/worker/voice-note', [
                'attendance_id' => $attendance->id,
                'text_note' => 'Trying to inject',
            ])
            ->assertNotFound();
    });

    it('CRM admin can download audio note via admin route', function () {
        Storage::fake('local');
        [, $employee, $company] = workerWithEmployee();
        $crmAdmin = User::factory()->admin()->forCompany($company)->create();

        $attendance = Attendance::factory()->for($employee)->for($company)->create([
            'check_in' => now()->startOfDay()->addHours(8),
            'check_out' => now()->startOfDay()->addHours(16),
        ]);

        Storage::disk('local')->put('attendance-voice-notes/fake.webm', 'fake-audio-data');

        // company_id is not mass-assignable (BelongsToCompany trait); use DB insert
        // to set it directly without triggering the creating hook.
        $noteId = DB::table('attendance_voice_notes')->insertGetId([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'text_note' => 'hello',
            'audio_path' => 'attendance-voice-notes/fake.webm',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $note = AttendanceVoiceNote::withoutGlobalScopes()->findOrFail($noteId);

        $this->actingAs($crmAdmin)
            ->get("/attendance/voice-notes/{$note->id}/download")
            ->assertOk();
    });

    it('lets a worker download their own audio note and audits it', function () {
        Storage::fake('local');
        [$user, $employee, $company] = workerWithEmployee();

        Storage::disk('local')->put('attendance-voice-notes/own.webm', 'audio');
        $attendance = Attendance::factory()->for($employee)->for($company)->create();
        $noteId = DB::table('attendance_voice_notes')->insertGetId([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'audio_path' => 'attendance-voice-notes/own.webm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/worker/voice-notes/{$noteId}/download")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => (new AttendanceVoiceNote)->getMorphClass(),
            'model_id' => (string) $noteId,
            'action' => 'viewed',
            'module' => 'attendance',
        ]);
    });

    it('marks the admin attendance grid cell when a voice note exists', function () {
        Storage::fake('local');
        [, $employee, $company] = workerWithEmployee(['active' => true]);
        $admin = User::factory()->admin()->forCompany($company)->create();

        $attendance = Attendance::factory()->for($employee)->for($company)->create([
            'date' => '2026-07-15',
        ]);
        DB::table('attendance_voice_notes')->insert([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'text_note' => 'Nota de la obra',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/attendance?month=2026-07')
            ->assertInertia(fn ($page) => $page
                ->where("grid.{$employee->id}.15.has_voice_note", true));
    });

    it('surfaces the voice note in the admin cell edit payload', function () {
        Storage::fake('local');
        [, $employee, $company] = workerWithEmployee(['active' => true]);
        $admin = User::factory()->admin()->forCompany($company)->create();

        $attendance = Attendance::factory()->for($employee)->for($company)->create([
            'date' => '2026-07-15',
        ]);
        DB::table('attendance_voice_notes')->insert([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'text_note' => 'Todo correcto',
            'audio_path' => 'attendance-voice-notes/x.webm',
            'duration_seconds' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get("/attendance?month=2026-07&edit={$attendance->id}")
            ->assertInertia(fn ($page) => $page
                ->where('editing.voice_note.text_note', 'Todo correcto')
                ->where('editing.voice_note.has_audio', true)
                ->where('editing.voice_note.duration_seconds', 9));
    });

    it('forbids a worker downloading another worker note in the same company', function () {
        Storage::fake('local');
        [$user, , $company] = workerWithEmployee();
        // Same company so CompanyScope passes and the ownership check (403), not
        // the tenant scope (404), is what refuses.
        $other = Employee::factory()->for($company)->create();

        Storage::disk('local')->put('attendance-voice-notes/other.webm', 'audio');
        $attendance = Attendance::factory()->for($other)->for($company)->create();
        $noteId = DB::table('attendance_voice_notes')->insertGetId([
            'attendance_id' => $attendance->id,
            'employee_id' => $other->id,
            'company_id' => $company->id,
            'audio_path' => 'attendance-voice-notes/other.webm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/worker/voice-notes/{$noteId}/download")
            ->assertForbidden();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature 2 — Worker expense
// ─────────────────────────────────────────────────────────────────────────────

describe('Feature 2 – worker expense', function () {
    it('worker can submit an expense without a receipt', function () {
        [$user, $employee] = workerWithEmployee();

        $this->actingAs($user)
            ->post('/worker/expenses', [
                'date' => today()->toDateString(),
                'amount' => '25.50',
                'category' => 'transport',
                'description' => 'Bus ticket to site',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_expenses', [
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'amount' => '25.50',
            'status' => 'pending',
        ]);
    });

    it('worker can submit an expense with a receipt photo', function () {
        Storage::fake('local');
        [$user, $employee] = workerWithEmployee();

        $receipt = UploadedFile::fake()->image('receipt.jpg');

        $this->actingAs($user)
            ->post('/worker/expenses', [
                'date' => today()->toDateString(),
                'amount' => '12.00',
                'category' => 'food',
                'description' => 'Lunch on site',
                'receipt' => $receipt,
            ])
            ->assertRedirect();

        $expense = WorkerExpense::where('employee_id', $employee->id)->firstOrFail();
        expect($expense->receipt_path)->not->toBeNull();
        Storage::disk('local')->assertExists($expense->receipt_path);
    });

    it('ignores company_id from request input', function () {
        [$user, $employee] = workerWithEmployee();
        $otherCompany = Company::factory()->create();

        $this->actingAs($user)
            ->post('/worker/expenses', [
                'date' => today()->toDateString(),
                'amount' => '10.00',
                'category' => 'other',
                'description' => 'Test',
                'company_id' => $otherCompany->id, // should be ignored
            ])
            ->assertRedirect();

        $expense = WorkerExpense::where('employee_id', $employee->id)->firstOrFail();
        expect($expense->company_id)->toBe($employee->company_id);
    });

    it('admin can approve an expense (admin bypasses permission matrix)', function () {
        [$workerUser, $employee, $company] = workerWithEmployee();
        // Admin role bypasses the module permission matrix — no rows needed.
        $admin = User::factory()->admin()->forCompany($company)->create();

        $expense = WorkerExpense::factory()->for($employee)->for($company)->create(['status' => 'pending']);

        $this->actingAs($admin)
            ->post("/worker-expenses/{$expense->id}/approve")
            ->assertRedirect();

        expect($expense->fresh()->status)->toBe(WorkerExpenseStatus::Approved);
    });

    it('admin can reject an expense with a reason', function () {
        [$workerUser, $employee, $company] = workerWithEmployee();
        $admin = User::factory()->admin()->forCompany($company)->create();

        $expense = WorkerExpense::factory()->for($employee)->for($company)->create(['status' => 'pending']);

        $this->actingAs($admin)
            ->post("/worker-expenses/{$expense->id}/reject", ['reason' => 'Not a valid work expense'])
            ->assertRedirect();

        $expense->refresh();
        expect($expense->status)->toBe(WorkerExpenseStatus::Rejected);
        expect($expense->rejection_reason)->toBe('Not a valid work expense');
    });

    it('manager without expenses.approve permission is denied', function () {
        [$workerUser, $employee, $company] = workerWithEmployee();
        // Manager role with no permission rows → Gate returns false → 403.
        $user = User::factory()->manager()->forCompany($company)->create();

        $expense = WorkerExpense::factory()->for($employee)->for($company)->create(['status' => 'pending']);

        $this->actingAs($user)
            ->post("/worker-expenses/{$expense->id}/approve")
            ->assertForbidden();
    });

    it('never ships expense amounts to the worker payload', function () {
        [$user, $employee, $company] = workerWithEmployee();

        WorkerExpense::factory()->for($employee)->for($company)->create([
            'status' => 'approved',
            'date' => today()->toDateString(),
            'amount' => '50.00',
        ]);

        // Fix 1 (client rule 2026-08-08, reinforced): NO financial data ever
        // reaches the worker payload — not merely hidden in the UI. The
        // recent_expenses prop (which carried amounts) was removed entirely.
        $this->actingAs($user)
            ->get('/worker')
            ->assertInertia(fn ($page) => $page->missing('recent_expenses'));
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature 3 — No financial data on the worker PWA (Fix 1)
// ─────────────────────────────────────────────────────────────────────────────

describe('Feature 3 – no financial data', function () {
    it('never ships pending advances / deductions to the worker payload', function () {
        [$user, $employee, $company] = workerWithEmployee();

        Advance::factory()->for($employee)->for($company)->create([
            'status' => AdvanceStatus::Approved->value,
            'amount' => '200',
            'payroll_month' => '2026-07',
        ]);

        // Workers see attendance only. The pending_advances prop (deductions)
        // was removed from the payload entirely — defence in depth, not UI hiding.
        $this->actingAs($user)
            ->get('/worker')
            ->assertInertia(fn ($page) => $page->missing('pending_advances'));
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature 4 — Vehicle sessions
// ─────────────────────────────────────────────────────────────────────────────

describe('Feature 4 – vehicle sessions', function () {
    it('worker without can_use_vehicles is denied the vehicle list', function () {
        [$user] = workerWithEmployee(['can_use_vehicles' => false]);

        $this->actingAs($user)
            ->get('/worker/vehicles')
            ->assertForbidden();
    });

    it('worker with can_use_vehicles sees the vehicle list', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        Vehicle::factory()->for($company)->create(['active' => true, 'is_available' => true]);

        $this->actingAs($user)
            ->get('/worker/vehicles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Worker/Vehicles')->has('vehicles', 1));
    });

    it('shows the company brand name (not the legal name) in the vehicle header', function () {
        [$user, , $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $company->update(['name' => 'Construcciones Y Proyectos Alovar Cinco, SL', 'brand_name' => 'Alovar']);

        $this->actingAs($user)
            ->get('/worker/vehicles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('worker.company', 'Alovar'));
    });

    it('worker can take an available vehicle', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create([
            'active' => true,
            'is_available' => true,
            'current_mileage' => 12500,
        ]);

        $this->actingAs($user)
            ->post("/worker/vehicles/{$vehicle->id}/take", [
                'starting_mileage' => 12500,
                'photo' => UploadedFile::fake()->image('cond.jpg'),
            ])
            ->assertRedirect();

        $vehicle->refresh();
        expect($vehicle->is_available)->toBeFalse();
        $this->assertDatabaseHas('vehicle_sessions', [
            'vehicle_id' => $vehicle->id,
            'employee_id' => $employee->id,
            'starting_mileage' => 12500,
            'returned_at' => null,
        ]);
    });

    it('worker cannot take a vehicle already taken', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        [, $other] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create(['active' => true, 'is_available' => false]);

        $this->actingAs($user)
            ->post("/worker/vehicles/{$vehicle->id}/take", [
                'starting_mileage' => 5000,
                'photo' => UploadedFile::fake()->image('cond.jpg'),
            ])
            ->assertSessionHasErrors();
    });

    it('worker cannot take a vehicle from another company', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $otherCompany = Company::factory()->create();
        $vehicle = Vehicle::factory()->for($otherCompany)->create(['active' => true, 'is_available' => true]);

        $this->actingAs($user)
            ->post("/worker/vehicles/{$vehicle->id}/take", ['starting_mileage' => 5000])
            ->assertNotFound();
    });

    it('worker can log fuel on their open session', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create(['active' => true, 'is_available' => false]);
        $session = VehicleSession::factory()->for($vehicle)->for($employee)->for($company)->create([
            'taken_at' => now(),
            'returned_at' => null,
        ]);

        $this->actingAs($user)
            ->post("/worker/vehicle-sessions/{$session->id}/fuel", ['amount' => 48.50])
            ->assertRedirect();

        // logFuel now creates a WorkerExpense (fuel refill as an expense)
        expect(WorkerExpense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->where('category', 'fuel')
            ->exists()
        )->toBeTrue();
    });

    it('worker cannot log fuel on another worker session', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        [, $other] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create(['active' => true, 'is_available' => false]);
        $session = VehicleSession::factory()->for($vehicle)->for($other)->for($company)->create([
            'taken_at' => now(),
            'returned_at' => null,
        ]);

        $this->actingAs($user)
            ->post("/worker/vehicle-sessions/{$session->id}/fuel", ['amount' => 20])
            ->assertForbidden();
    });

    it('worker can return a vehicle and session closes', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create([
            'active' => true,
            'is_available' => false,
            'current_mileage' => 12500,
        ]);
        $session = VehicleSession::factory()->for($vehicle)->for($employee)->for($company)->create([
            'taken_at' => now()->subHour(),
            'returned_at' => null,
            'starting_mileage' => 12500,
        ]);

        $this->actingAs($user)
            ->post("/worker/vehicle-sessions/{$session->id}/return", [
                'ending_mileage' => 12650,
                'ending_fuel_level' => 70,
                'return_notes' => 'All good',
                'photo' => UploadedFile::fake()->image('ret.jpg'),
            ])
            ->assertRedirect();

        $session->refresh();
        expect($session->returned_at)->not->toBeNull();
        expect($session->km_driven)->toBe(150);
        expect($vehicle->fresh()->is_available)->toBeTrue();
    });

    it('VehicleSessionService is idempotent on take — second take rejected', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create(['is_available' => true]);

        $service = app(VehicleSessionService::class);
        $service->take($vehicle, $employee, 1000, null);

        expect(fn () => $service->take($vehicle, $employee, 1000, null))
            ->toThrow(ValidationException::class);
    });

    it('odometer cannot go backwards on return', function () {
        [$user, $employee, $company] = workerWithEmployee(['can_use_vehicles' => true]);
        $vehicle = Vehicle::factory()->for($company)->create(['is_available' => false]);
        $session = VehicleSession::factory()->for($vehicle)->for($employee)->for($company)->create([
            'taken_at' => now()->subHour(),
            'returned_at' => null,
            'starting_mileage' => 5000,
        ]);

        $this->actingAs($user)
            ->post("/worker/vehicle-sessions/{$session->id}/return", [
                'ending_mileage' => 4999, // backwards
                'photo' => UploadedFile::fake()->image('ret.jpg'),
            ])
            ->assertSessionHasErrors('ending_mileage');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenancy isolation
// ─────────────────────────────────────────────────────────────────────────────

describe('tenancy isolation', function () {
    it('worker expense is stored under own company_id, not another', function () {
        [$user, $employee, $company] = workerWithEmployee();
        $otherCompany = Company::factory()->create();

        $this->actingAs($user)
            ->post('/worker/expenses', [
                'date' => today()->toDateString(),
                'amount' => '15.00',
                'category' => 'other',
                'description' => 'Sneak',
                'company_id' => $otherCompany->id,
            ])
            ->assertRedirect();

        $expense = WorkerExpense::where('employee_id', $employee->id)->first();
        expect($expense->company_id)->toBe($company->id)
            ->not->toBe($otherCompany->id);
    });

    it('admin cannot approve expenses from a different company (tenant 404)', function () {
        [$workerUser, $employee, $company] = workerWithEmployee();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->admin()->forCompany($otherCompany)->create();

        $expense = WorkerExpense::factory()->for($employee)->for($company)->create(['status' => 'pending']);

        // CompanyScope makes the expense invisible → 404 (not 403).
        $this->actingAs($admin)
            ->post("/worker-expenses/{$expense->id}/approve")
            ->assertNotFound();
    });
});
