<?php

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
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
    // handful of queries (+1 constant for the designation catalogue). Without
    // eager loading it would be 25+.
    expect($count)->toBeLessThan(16);
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

    // The grid fetches employees + records + deployed-in as a fixed set of
    // queries and assembles the cells in memory — flat regardless of headcount.
    expect($count)->toBeLessThan(20);
});

it('builds the dashboard with a bounded query count', function (): void {
    Employee::factory()->count(25)->create(['company_id' => $this->company->id]);

    // Prime nothing — measure a cold (uncached) dashboard build.
    Cache::flush();

    $count = countQueries(fn () => $this->actingAs($this->admin)->get('/dashboard')->assertOk());

    // ~8 KPIs + 3 charts + 2 panels: a fixed set of aggregate queries, none
    // per-employee. (+2 constant queries: the P&L cache signature now also
    // tracks measurements + invoices so approving production or marking an
    // invoice paid refreshes the widget instead of waiting out the TTL.)
    expect($count)->toBeLessThan(42);
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
