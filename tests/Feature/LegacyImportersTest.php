<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\LegacyIdMap;
use App\Models\Setting;
use App\Models\User;
use App\Services\LegacyImport\Importers\SettingsImporter;
use App\Services\LegacyImport\Importers\UsersImporter;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importers run against an in-memory stand-in for the legacy database
 * until the live dump arrives (DATA_MIGRATION.md §1) — the schema below
 * mirrors the legacy CODEBASE_REVIEW.md structure.
 */
beforeEach(function (): void {
    config([
        'database.connections.legacy' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    DB::purge('legacy');

    Schema::connection('legacy')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->string('role')->default('accountant');
        $table->string('status')->default('active');
    });

    Schema::connection('legacy')->create('settings', function (Blueprint $table): void {
        $table->id();
        $table->string('key');
        $table->text('value')->nullable();
    });

    Company::factory()->create(['name' => 'Empresa Uno']);
});

it('imports legacy users with role mapping and password hash carry-over', function (): void {
    $legacyHash = password_hash('legacy-pass', PASSWORD_BCRYPT);

    DB::connection('legacy')->table('users')->insert([
        ['name' => 'Admin Legacy', 'email' => 'admin@legacy.es', 'password' => $legacyHash, 'role' => 'admin', 'status' => 'active'],
        ['name' => 'Contable', 'email' => 'contable@legacy.es', 'password' => $legacyHash, 'role' => 'accountant', 'status' => 'active'],
        ['name' => 'Inactivo', 'email' => 'baja@legacy.es', 'password' => $legacyHash, 'role' => 'viewer', 'status' => 'disabled'],
    ]);

    $result = app(UsersImporter::class)->run();

    expect($result->imported)->toBe(3)->and($result->exceptions)->toBe(0);

    $admin = User::query()->where('email', 'admin@legacy.es')->firstOrFail();
    $staff = User::query()->where('email', 'contable@legacy.es')->firstOrFail();
    $inactive = User::query()->where('email', 'baja@legacy.es')->firstOrFail();

    expect($admin->role)->toBe(UserRole::SuperAdmin)
        ->and($admin->company_id)->toBeNull()
        ->and($staff->role)->toBe(UserRole::User)
        ->and($staff->company_id)->toBe(Company::query()->value('id'))
        ->and($staff->getAuthPassword())->toBe($legacyHash) // hash untouched
        ->and($inactive->active)->toBeFalse();
});

it('is idempotent across re-runs', function (): void {
    DB::connection('legacy')->table('users')->insert([
        'name' => 'Uno', 'email' => 'uno@legacy.es',
        'password' => password_hash('x', PASSWORD_BCRYPT), 'role' => 'viewer', 'status' => 'active',
    ]);

    $first = app(UsersImporter::class)->run();
    $second = app(UsersImporter::class)->run();

    expect($first->imported)->toBe(1)
        ->and($second->imported)->toBe(0)
        ->and($second->skipped)->toBe(1)
        ->and(User::query()->where('email', 'uno@legacy.es')->count())->toBe(1);
});

it('reports email collisions as exceptions instead of overwriting', function (): void {
    User::factory()->forCompany(Company::query()->firstOrFail())->create(['email' => 'choque@legacy.es']);

    DB::connection('legacy')->table('users')->insert([
        'name' => 'Choque', 'email' => 'choque@legacy.es',
        'password' => password_hash('x', PASSWORD_BCRYPT), 'role' => 'viewer', 'status' => 'active',
    ]);

    $result = app(UsersImporter::class)->run();

    expect($result->imported)->toBe(0)
        ->and($result->exceptions)->toBe(1)
        ->and($result->exceptionsReportPath)->not->toBeNull();

    if ($result->exceptionsReportPath !== null) {
        unlink($result->exceptionsReportPath);
    }
});

it('imports only whitelisted settings and never secrets', function (): void {
    DB::connection('legacy')->table('settings')->insert([
        ['key' => 'app_name', 'value' => 'VertoCRM Legacy'],
        ['key' => 'session_timeout', 'value' => '90'],
        ['key' => 'ai_api_key', 'value' => 'sk-super-secret'],
        ['key' => 'random_legacy_key', 'value' => 'whatever'],
    ]);

    $result = app(SettingsImporter::class)->run();

    $settings = app(SettingsService::class);

    expect($result->imported)->toBe(2)
        ->and($result->skipped)->toBe(2)
        ->and($settings->get('general.app_name'))->toBe('VertoCRM Legacy')
        ->and($settings->get('general.session_timeout_minutes'))->toBe('90')
        ->and(Setting::query()->where('value', 'like', '%sk-super-secret%')->exists())->toBeFalse();
});

it('rolls back all writes in dry-run mode but still counts work', function (): void {
    DB::connection('legacy')->table('users')->insert([
        'name' => 'Dry', 'email' => 'dry@legacy.es',
        'password' => password_hash('x', PASSWORD_BCRYPT), 'role' => 'viewer', 'status' => 'active',
    ]);

    $result = app(UsersImporter::class)->run(dryRun: true);

    expect($result->imported)->toBe(1)
        ->and(User::query()->where('email', 'dry@legacy.es')->exists())->toBeFalse()
        ->and(LegacyIdMap::query()->count())->toBe(0);
});
