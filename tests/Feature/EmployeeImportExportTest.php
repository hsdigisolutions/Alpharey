<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\LegacyImport\Importers\EmployeesImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('exports employees as an Excel download', function (): void {
    Employee::factory()->count(2)->forCompany($this->company)->create();

    $response = $this->actingAs($this->admin)->get('/employees/export?format=excel');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

it('exports employees as a PDF download', function (): void {
    Employee::factory()->forCompany($this->company)->create();

    $response = $this->actingAs($this->admin)->get('/employees/export?format=pdf');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('denies export without export permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'employees', 'can_view' => true,
    ]);

    $this->actingAs($user)->get('/employees/export?format=excel')->assertForbidden();
});

it('audits an employee export', function (): void {
    Employee::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)->get('/employees/export?format=excel');

    expect(AuditLog::query()->where('action', 'exported')->where('module', 'employees')->exists())
        ->toBeTrue();
});

it('imports employees from an uploaded spreadsheet', function (): void {
    $csv = "nombre,email,tipo_salario,tarifa\n"
        ."Juan Nuevo,juan@ejemplo.es,hourly,15\n"
        ."Ana Nueva,ana@ejemplo.es,daily,90\n";

    $file = UploadedFile::fake()->createWithContent('empleados.csv', $csv);

    $this->actingAs($this->admin)->post('/employees/import', ['file' => $file])->assertRedirect();

    expect(Employee::query()->whereIn('full_name', ['Juan Nuevo', 'Ana Nueva'])->count())->toBe(2);
});

it('reports invalid import rows while importing the valid ones', function (): void {
    $csv = "nombre,email\n"
        .",missing-name@ejemplo.es\n"        // no name → failure
        ."Válido Uno,valido@ejemplo.es\n";   // ok

    $file = UploadedFile::fake()->createWithContent('empleados.csv', $csv);

    $this->actingAs($this->admin)->post('/employees/import', ['file' => $file])->assertRedirect();

    expect(Employee::query()->where('full_name', 'Válido Uno')->exists())->toBeTrue()
        ->and(Employee::query()->count())->toBe(1);
});

/*
 * Legacy employees importer — against an in-memory stand-in (the live
 * dump is still pending, DATA_MIGRATION.md §1).
 */
it('imports legacy employees to company 1 with wage-type normalization', function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');
    Schema::connection('legacy')->create('employees', function (Blueprint $table): void {
        $table->id();
        $table->string('full_name');
        $table->string('employee_code')->nullable();
        $table->string('nif')->nullable();
        $table->string('wage_type')->nullable();
        $table->string('wage_rate')->nullable();
        $table->boolean('active')->default(true);
    });

    DB::connection('legacy')->table('employees')->insert([
        ['full_name' => 'Legacy Uno', 'employee_code' => 'OLD-1', 'nif' => '11111111H', 'wage_type' => 'meter', 'wage_rate' => '3.5', 'active' => 1],
        ['full_name' => 'Legacy Dos', 'employee_code' => 'OLD-2', 'nif' => '22222222J', 'wage_type' => 'hourly', 'wage_rate' => '14', 'active' => 1],
    ]);

    $result = app(EmployeesImporter::class)->run();

    expect($result->imported)->toBe(2)->and($result->exceptions)->toBe(0);

    $uno = Employee::withoutGlobalScopes()->where('full_name', 'Legacy Uno')->firstOrFail();

    expect($uno->company_id)->toBe($this->company->id)
        ->and($uno->wage_type?->value)->toBe('per_meter') // meter → per_meter
        ->and($uno->nif)->toBe('11111111H');              // decrypts correctly
});
