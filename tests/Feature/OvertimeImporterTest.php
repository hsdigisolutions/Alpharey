<?php

use App\Enums\OvertimePolicyType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LegacyIdMap;
use App\Models\OvertimePolicy;
use App\Models\User;
use App\Services\LegacyImport\Importers\AttendanceImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ---------------- Overtime policy settings ---------------- */

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('creates an overtime policy in the active company', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($superAdmin)->post("/welcome/{$this->company->id}/select");

    $this->post('/admin/overtime-policies', [
        'name' => 'Estándar 25%', 'type' => OvertimePolicyType::Percentage->value,
        'rate' => 25, 'daily_threshold_hours' => 9,
    ])->assertRedirect();

    $policy = OvertimePolicy::withoutGlobalScopes()->where('name', 'Estándar 25%')->firstOrFail();
    expect($policy->company_id)->toBe($this->company->id)
        ->and($policy->type)->toBe(OvertimePolicyType::Percentage);
});

it('denies overtime policy management to regular users', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->post('/admin/overtime-policies', ['name' => 'X', 'type' => 'none'])
        ->assertForbidden();
});

/* ---------------- Legacy attendance importer ---------------- */

it('imports legacy attendance with snapshots carried over verbatim', function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');
    Schema::connection('legacy')->create('attendance', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('employee_id');
        $t->unsignedBigInteger('project_id')->nullable();
        $t->date('date');
        $t->string('mode')->nullable();
        $t->decimal('hours_worked', 6, 2)->nullable();
        $t->decimal('overtime_hours', 6, 2)->nullable();
        $t->string('status')->nullable();
        $t->string('wage_type')->nullable();
        $t->decimal('wage_rate', 10, 2)->nullable();
        $t->decimal('hourly_rate', 10, 2)->nullable();
        $t->decimal('total_amount', 12, 2)->nullable();
    });

    // Map a legacy employee id → a real employee via legacy_id_map
    $employee = Employee::factory()->forCompany($this->company)->create();
    LegacyIdMap::query()->create(['entity_type' => 'employees', 'legacy_id' => '77', 'new_id' => (string) $employee->id]);

    DB::connection('legacy')->table('attendance')->insert([
        'employee_id' => 77, 'date' => '2024-05-10', 'mode' => 'hourly',
        'hours_worked' => 8, 'overtime_hours' => 1, 'status' => 'present',
        'wage_type' => 'hourly', 'wage_rate' => 12.5, 'hourly_rate' => 12.5, 'total_amount' => 112.5,
    ]);

    $result = app(AttendanceImporter::class)->run();

    expect($result->imported)->toBe(1)->and($result->exceptions)->toBe(0);

    $record = Attendance::withoutGlobalScopes()->firstOrFail();
    // Snapshot + total carried over verbatim, NOT recomputed
    expect((float) $record->hourly_rate_snapshot)->toBe(12.5)
        ->and((float) $record->total_amount)->toBe(112.5)
        ->and($record->employee_id)->toBe($employee->id);
});

it('reports legacy attendance whose employee was not imported', function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');
    Schema::connection('legacy')->create('attendance', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('employee_id');
        $t->unsignedBigInteger('project_id')->nullable();
        $t->date('date');
        $t->string('status')->nullable();
    });
    DB::connection('legacy')->table('attendance')->insert([
        'employee_id' => 999, 'date' => '2024-05-10', 'status' => 'present',
    ]);

    $result = app(AttendanceImporter::class)->run();

    expect($result->imported)->toBe(0)->and($result->exceptions)->toBe(1);

    if ($result->exceptionsReportPath !== null) {
        unlink($result->exceptionsReportPath);
    }
});
