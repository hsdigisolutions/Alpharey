<?php

use App\Enums\PaymentStatus;
use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LegacyIdMap;
use App\Models\Payroll;
use App\Services\LegacyImport\Importers\AttendanceImporter;
use App\Services\LegacyImport\Importers\ExpensesImporter;
use App\Services\LegacyImport\Importers\PayrollsImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regression tests for the value shapes the REAL legacy dump actually
 * contains — the ones the earlier in-memory stand-in didn't, and which broke
 * the import when the live dump arrived (DATA_MIGRATION.md §3.4b / §5).
 *
 * Two of these were not "flag a row" bugs — they were ValueErrors that aborted
 * the ENTIRE migration on the first offending row. That is the worst possible
 * failure mode for a one-shot cutover, so they are pinned hard.
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

    $this->company = Company::factory()->create();
});

/**
 * Map a legacy id to a freshly-created new-system row, the way an upstream
 * importer would have, so a dependent importer can resolve it.
 */
function mapLegacy(string $entityType, int $legacyId, int $newId): void
{
    LegacyIdMap::query()->create([
        'entity_type' => $entityType,
        'legacy_id' => (string) $legacyId,
        'new_id' => (string) $newId,
    ]);
}

/**
 * The legacy `expenses` shape, with the columns the importer reads directly
 * (it accesses several with `!== null`, which errors on a missing column).
 */
function legacyExpensesTable(): void
{
    Schema::connection('legacy')->create('expenses', function (Blueprint $t): void {
        $t->id();
        $t->string('number')->nullable();
        $t->string('type')->nullable();
        $t->integer('category_id')->nullable();
        $t->string('category')->nullable();
        $t->integer('vendor_id')->nullable();
        $t->integer('project_id')->nullable();
        $t->integer('employee_id')->nullable();
        $t->date('date')->nullable();
        $t->date('due_date')->nullable();
        $t->decimal('amount', 12, 2)->nullable();
        $t->decimal('subtotal', 12, 2)->nullable();
        $t->decimal('total', 12, 2)->nullable();
        $t->decimal('iva_percent', 6, 2)->nullable();
        $t->decimal('vat_percent', 6, 2)->nullable();
        $t->decimal('vat_amount', 12, 2)->nullable();
        $t->string('payment_method')->nullable();
        $t->string('payment_status')->nullable();
        $t->date('payment_date')->nullable();
        $t->boolean('is_reimbursable')->default(false);
        $t->boolean('approved')->default(false);
        $t->text('notes')->nullable();
    });
}

it('imports attendance whose wage_type is the legacy day/hour spelling', function (): void {
    Schema::connection('legacy')->create('attendance', function (Blueprint $t): void {
        $t->id();
        $t->integer('employee_id');
        $t->integer('project_id')->nullable();
        $t->date('date');
        $t->string('status')->nullable();
        $t->string('wage_type')->nullable();
        $t->decimal('wage_rate', 10, 2)->nullable();
        $t->decimal('hourly_rate', 10, 2)->nullable();
        $t->decimal('hours_worked', 8, 2)->nullable();
        $t->decimal('overtime_hours', 8, 2)->nullable();
        $t->decimal('total_amount', 12, 2)->nullable();
        $t->boolean('is_paid')->default(false);
    });

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    mapLegacy('employees', 73, $employee->id);

    DB::connection('legacy')->table('attendance')->insert([
        ['employee_id' => 73, 'date' => '2026-04-01', 'status' => 'present', 'wage_type' => 'day', 'total_amount' => 80],
        ['employee_id' => 73, 'date' => '2026-04-02', 'status' => 'present', 'wage_type' => 'hour', 'total_amount' => 12],
    ]);

    // The whole point: this used to throw a ValueError on the WageType cast.
    $result = app(AttendanceImporter::class)->run();

    expect($result->imported)->toBe(2)->and($result->exceptions)->toBe(0);

    $rows = Attendance::query()->withoutGlobalScopes()->orderBy('date')->get();

    expect($rows->pluck('wage_type_snapshot')->all())
        ->toBe([WageType::Daily, WageType::Hourly]);
});

it('imports expenses whose payment_method says WHO paid, not how', function (): void {
    legacyExpensesTable();

    DB::connection('legacy')->table('expenses')->insert([
        ['amount' => 100, 'total' => 100, 'payment_method' => 'employee', 'payment_status' => 'unpaid', 'date' => '2026-04-01'],
        ['amount' => 50, 'total' => 50, 'payment_method' => 'company_card', 'payment_status' => 'unpaid', 'date' => '2026-04-02'],
        ['amount' => 30, 'total' => 30, 'payment_method' => 'bank', 'payment_status' => 'unpaid', 'date' => '2026-04-03'],
        ['amount' => 20, 'total' => 20, 'payment_method' => 'not_paid', 'payment_status' => 'unpaid', 'date' => '2026-04-04'],
    ]);

    // Used to die on the PaymentMethod cast at the first 'employee' row.
    $result = app(ExpensesImporter::class)->run();

    expect($result->imported)->toBe(4);

    $expenses = Expense::query()->withoutGlobalScopes()->get();

    // Only 'bank' is a payment method in this schema's sense; the rest are
    // facts recorded elsewhere, so they map to a blank method, not a crash.
    expect($expenses->firstWhere('total', '30.00')->payment_method?->value)->toBe('bank_transfer')
        ->and($expenses->firstWhere('total', '100.00')->payment_method)->toBeNull()
        ->and($expenses->firstWhere('total', '50.00')->payment_method)->toBeNull()
        ->and($expenses->firstWhere('total', '20.00')->payment_method)->toBeNull();
});

it('reads a reimbursed expense as settled, not as an outstanding debt', function (): void {
    legacyExpensesTable();

    DB::connection('legacy')->table('expenses')->insert([
        'total' => 51.05, 'payment_status' => 'reimbursed', 'date' => '2026-04-01',
    ]);

    app(ExpensesImporter::class)->run();

    // 'reimbursed' → paid. The old whitelist fell back to 'unpaid', which would
    // have re-opened a debt the company had already settled with the worker.
    expect(Expense::query()->withoutGlobalScopes()->first()->payment_status)
        ->toBe(PaymentStatus::Paid);
});

it('resolves the payroll period from the legacy payroll_month column', function (): void {
    Schema::connection('legacy')->create('payrolls', function (Blueprint $t): void {
        $t->id();
        $t->integer('employee_id');
        $t->string('payroll_month')->nullable();
        $t->decimal('net_amount', 12, 2)->nullable();
        $t->string('wage_type')->nullable();
    });

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    mapLegacy('employees', 1, $employee->id);

    DB::connection('legacy')->table('payrolls')->insert([
        'employee_id' => 1, 'payroll_month' => '2026-04', 'net_amount' => 600, 'wage_type' => 'daily',
    ]);

    // Used to report "Could not resolve the payroll month" for every row,
    // because it only looked at `month`, never `payroll_month`.
    $result = app(PayrollsImporter::class)->run();

    expect($result->imported)->toBe(1)->and($result->exceptions)->toBe(0);

    $payroll = Payroll::query()->withoutGlobalScopes()->first();
    expect($payroll->month)->toBe('2026-04')
        ->and((float) $payroll->net_amount)->toBe(600.0);
});
