<?php

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 performance pass — N+1 guards.
 *
 * These pin that the heavy list endpoints issue a number of queries that does
 * NOT grow with the row count. The real dump has 264 employees and 4 911
 * attendance rows, so a per-row query on any of these would be felt. Each test
 * seeds well past one page and asserts the query count stays flat — if someone
 * later drops an eager-load, the count jumps and the test fails.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
});

/** Count the queries a callback runs. */
function countQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('lists employees with a bounded query count regardless of row count', function (): void {
    // 30 employees, each with a document — a naive compliance lookup would
    // fire one query per employee for the documents relation.
    $employees = Employee::factory()->count(30)->create(['company_id' => $this->company->id]);
    foreach ($employees as $employee) {
        $doc = new Document(['category' => 'employee', 'type_key' => 'nif', 'name' => 'NIF']);
        $doc->documentable_type = Employee::class;
        $doc->documentable_id = $employee->id;
        $doc->company_id = $this->company->id;
        $doc->is_current = true;
        $doc->expiry_date = now()->addDays(45)->toDateString();
        $doc->save();
    }

    $count = countQueries(fn () => $this->actingAs($this->admin)->get('/employees')->assertOk());

    // A page is 25 rows; with the documents relation eager-loaded this is a
    // handful of queries (+1 constant for the designation catalogue, +1 for the
    // single summary-stats aggregate, +1 for the active-deployment badge map,
    // +1 for the Item 8 "Referido por" referrer dropdown list — all page-level
    // constants, never per-row). Without eager loading it would be 25+.
    expect($count)->toBeLessThan(19);
});

it('renders the attendance grid with a bounded query count', function (): void {
    $employees = Employee::factory()->count(20)->create(['company_id' => $this->company->id]);
    $day = now()->startOfMonth()->toDateString();
    foreach ($employees as $employee) {
        Attendance::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'date' => $day,
            'status' => AttendanceStatus::Present,
        ]);
    }

    $month = now()->format('Y-m');
    $count = countQueries(fn () => $this->actingAs($this->admin)->get("/attendance?month={$month}")->assertOk());

    // The grid fetches employees + records + deployed-in + the deployed-OUT
    // host rows as a fixed set of queries and assembles the cells in memory —
    // flat regardless of headcount (each is one page-level query, never per-row).
    expect($count)->toBeLessThan(21);
});

it('builds the dashboard with a bounded query count', function (): void {
    $employees = Employee::factory()->count(25)->create(['company_id' => $this->company->id]);

    // Seed a few projects, each with attendance from several workers, so the
    // per-project profitability path (DashboardService → ProfitabilityService::
    // companyRows → forProject per project) is actually EXERCISED — an empty
    // project list would leave that loop untested and blind to a per-project N+1.
    // Operational % is on so the overhead aggregates (operationalBase +
    // employerTaxTotal) run too. The budget must stay FLAT as attendance/worker
    // volume grows within these projects — forProject aggregates in SQL, never
    // per row — so more attendance below must not raise the count.
    app(SettingsService::class)->set("operational.cost_pct.{$this->company->id}", 10);
    foreach (range(1, 3) as $i) {
        $project = Project::factory()->forCompany($this->company)->create([
            'billing_type' => 'hourly', 'client_hour_rate' => '20',
        ]);
        foreach ($employees->take(5) as $offset => $emp) {
            Attendance::factory()->create([
                'company_id' => $this->company->id, 'employee_id' => $emp->id,
                'project_id' => $project->id, 'date' => sprintf('2026-09-%02d', $i * 5 + $offset),
                'status' => 'present', 'total_amount' => '100',
            ]);
        }
    }

    // Prime nothing — measure a cold (uncached) dashboard build.
    Cache::flush();

    $count = countQueries(fn () => $this->actingAs($this->admin)->get('/dashboard')->assertOk());

    // ~8 KPIs + 3 charts + 2 panels + the per-project P&L (3 projects): a fixed
    // set of aggregate queries, none per-employee or per-attendance-row. The P&L
    // cache signature adds a small CONSTANT set of MAX(updated_at) reads
    // (measurements, invoices, task_progress + production_tasks for task-based
    // billing, and the operational-cost % setting + employees MAX for the Item 7
    // overhead line). forProject folds the labour/tax/overhead reads into a single
    // aggregate per project (~63 total here), so a per-project query re-added to it
    // would push 3 projects × +1 over this bound; a per-row one blows it outright.
    expect($count)->toBeLessThan(66);
});

it('serves the dashboard from cache on the second hit', function (): void {
    Employee::factory()->count(10)->create(['company_id' => $this->company->id]);
    Cache::flush();

    $this->actingAs($this->admin)->get('/dashboard')->assertOk();

    // Second hit inside the 120s window: the payload is cached, so only the
    // request-lifecycle queries (session, user, notifications) run — far fewer
    // than a cold build's aggregates.
    $cached = countQueries(fn () => $this->actingAs($this->admin)->get('/dashboard')->assertOk());

    expect($cached)->toBeLessThan(15);
});
