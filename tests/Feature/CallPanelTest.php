<?php

use App\Enums\CallOutcome;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCallLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
    $this->actingAs($this->admin);
});

function logCall(int $employeeId, int $companyId, array $overrides = []): EmployeeCallLog
{
    $call = new EmployeeCallLog(array_merge([
        'called_at' => now(),
        'remarks' => 'Llamada de seguimiento',
        // Mirror production: every logged call carries an outcome (default
        // connected — the common case + the migration backfill).
        'call_outcome' => 'connected',
    ], $overrides));
    $call->employee_id = $employeeId;
    $call->company_id = $companyId;
    $call->save();

    return $call;
}

/**
 * The indicator (Screen 13): red = overdue follow-up, amber = due today,
 * green = nothing outstanding.
 */
it('shows red when a follow-up is overdue', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    logCall($employee->id, $this->company->id, ['follow_up_date' => now()->subDays(3)->toDateString()]);

    $this->get('/calls')
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.indicator', 'red'));
});

it('shows amber when a follow-up is due today', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    logCall($employee->id, $this->company->id, ['follow_up_date' => now()->toDateString()]);

    $this->get('/calls')
        ->assertInertia(fn ($page) => $page->where('employees.0.indicator', 'amber'));
});

it('shows green when there is nothing outstanding', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    logCall($employee->id, $this->company->id, ['follow_up_date' => null]);

    $this->get('/calls')
        ->assertInertia(fn ($page) => $page->where('employees.0.indicator', 'green'));
});

/**
 * The soonest OUTSTANDING follow-up drives the light, not the latest call's:
 * a newer call with no follow-up must not hide an older one that is overdue.
 */
it('does not let a newer call hide an older overdue follow-up', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    logCall($employee->id, $this->company->id, [
        'called_at' => now()->subWeek(),
        'follow_up_date' => now()->subDays(2)->toDateString(), // overdue
    ]);
    logCall($employee->id, $this->company->id, [
        'called_at' => now(),
        'follow_up_date' => null, // newer, but says nothing about the follow-up
    ]);

    $this->get('/calls')
        ->assertInertia(fn ($page) => $page->where('employees.0.indicator', 'red'));
});

/**
 * Filter tabs.
 */
it('filters to workers with a pending follow-up', function (): void {
    $withFollowUp = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Con Seguimiento']);
    $without = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Sin Seguimiento']);

    logCall($withFollowUp->id, $this->company->id, ['follow_up_date' => now()->toDateString()]);
    logCall($without->id, $this->company->id, ['follow_up_date' => null]);

    $this->get('/calls?tab=pending')
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Con Seguimiento'));
});

it('filters to workers not contacted this week', function (): void {
    $stale = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Antiguo']);
    $fresh = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Reciente']);

    logCall($stale->id, $this->company->id, ['called_at' => now()->startOfWeek()->subDays(3)]);
    logCall($fresh->id, $this->company->id, ['called_at' => now()]);

    $this->get('/calls?tab=not_contacted')
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Antiguo'));
});

it('counts a never-contacted worker as not contacted this week', function (): void {
    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Nunca Llamado']);

    $this->get('/calls?tab=not_contacted')
        ->assertInertia(fn ($page) => $page->has('employees', 1));
});

/**
 * Month overview — four SEPARATED categories (calls made · connected ·
 * not connected · follow-ups).
 */
it('reports the four overview categories for the default (current) month', function (): void {
    $a = Employee::factory()->create(['company_id' => $this->company->id]);
    $b = Employee::factory()->create(['company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id]); // never called

    // A reached (connected) + a follow-up today; B tried but not reached.
    logCall($a->id, $this->company->id, ['called_at' => now(), 'call_outcome' => 'connected', 'follow_up_date' => now()->toDateString()]);
    logCall($b->id, $this->company->id, ['called_at' => now(), 'call_outcome' => 'no_answer']);

    $this->get('/calls')
        ->assertInertia(fn ($page) => $page
            ->where('filters.range', 'month')
            ->where('overview.calls_made.count', 2)      // total calls
            ->where('overview.connected.count', 1)       // A reached
            ->where('overview.not_connected.count', 1)   // B not reached
            ->where('overview.follow_ups.count', 1));    // A's follow-up today
});

it('recomputes the four categories over a custom date range', function (): void {
    $a = Employee::factory()->create(['company_id' => $this->company->id]);
    $b = Employee::factory()->create(['company_id' => $this->company->id]);

    // June: A reached twice (one with a June follow-up). July: B not reached.
    logCall($a->id, $this->company->id, ['called_at' => '2026-06-05 09:00:00', 'call_outcome' => 'connected', 'follow_up_date' => '2026-06-20']);
    logCall($a->id, $this->company->id, ['called_at' => '2026-06-15 09:00:00', 'call_outcome' => 'connected']);
    logCall($b->id, $this->company->id, ['called_at' => '2026-07-10 09:00:00', 'call_outcome' => 'no_answer']);

    // June window: 2 calls, A connected (1 person), 0 not-connected, 1 follow-up.
    $this->get('/calls?from=2026-06-01&to=2026-06-30')
        ->assertInertia(fn ($page) => $page
            ->where('filters.range', 'custom')
            ->where('filters.from', '2026-06-01')
            ->where('filters.to', '2026-06-30')
            ->where('overview.calls_made.count', 2)
            ->where('overview.connected.count', 1)
            ->where('overview.not_connected.count', 0)
            ->where('overview.follow_ups.count', 1));

    // July window: 1 call, 0 connected, B not-connected (1 person), 0 follow-ups.
    $this->get('/calls?from=2026-07-01&to=2026-07-31')
        ->assertInertia(fn ($page) => $page
            ->where('overview.calls_made.count', 1)
            ->where('overview.connected.count', 0)
            ->where('overview.not_connected.count', 1)
            ->where('overview.follow_ups.count', 0));
});

it('selects the overview by month and splits connected vs not-connected per person', function (): void {
    $reached = Employee::factory()->create(['company_id' => $this->company->id]);
    $missed = Employee::factory()->create(['company_id' => $this->company->id]);
    $mixed = Employee::factory()->create(['company_id' => $this->company->id]);

    // In June: reached (connected), missed (no_answer only), mixed (a miss THEN a
    // connect — reached at least once ⇒ Connected).
    logCall($reached->id, $this->company->id, ['called_at' => '2026-06-10 09:00:00', 'call_outcome' => 'connected']);
    logCall($missed->id, $this->company->id, ['called_at' => '2026-06-11 09:00:00', 'call_outcome' => 'no_answer']);
    logCall($mixed->id, $this->company->id, ['called_at' => '2026-06-12 09:00:00', 'call_outcome' => 'no_answer']);
    logCall($mixed->id, $this->company->id, ['called_at' => '2026-06-13 09:00:00', 'call_outcome' => 'connected']);

    $this->get('/calls?month=2026-06')
        ->assertInertia(fn ($page) => $page
            ->where('filters.range', 'month')
            ->where('filters.month', '2026-06')
            ->where('overview.calls_made.count', 4)
            ->where('overview.connected.count', 2)       // reached + mixed
            ->where('overview.not_connected.count', 1)); // missed only
});

it('loads the selected worker call history', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id, 'mobile' => '600123456']);
    logCall($employee->id, $this->company->id, ['remarks' => 'Primera llamada']);

    $this->get("/calls?employee={$employee->id}")
        ->assertInertia(fn ($page) => $page
            ->where('selected.mobile', '600123456')
            ->has('selected.calls', 1)
            ->where('selected.calls.0.remarks', 'Primera llamada'));
});

it('logs a call from the panel', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $this->post('/calls', [
        'employee_id' => $employee->id,
        'remarks' => 'Hablado sobre el turno',
        'follow_up_date' => now()->addWeek()->toDateString(),
    ])->assertRedirect();

    $call = EmployeeCallLog::query()->first();

    expect($call->remarks)->toBe('Hablado sobre el turno')
        ->and($call->called_by)->toBe($this->admin->id)
        ->and($call->company_id)->toBe($this->company->id);
});

/**
 * Tenancy + permissions (Rule 11).
 */
it('shows a user only the workers of their own company', function (): void {
    $other = Company::factory()->create();
    Employee::factory()->create(['company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $other->id]);

    $this->get('/calls')->assertInertia(fn ($page) => $page->has('employees', 1));
});

it('refuses to log a call against another company employee', function (): void {
    $other = Company::factory()->create();
    $foreign = Employee::factory()->create(['company_id' => $other->id]);

    $this->post('/calls', [
        'employee_id' => $foreign->id,
        'remarks' => 'No debería registrarse',
    ])->assertSessionHasErrors('employee_id');

    expect(EmployeeCallLog::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not leak another company call history through the selector', function (): void {
    $other = Company::factory()->create();
    $foreign = Employee::factory()->create(['company_id' => $other->id]);
    logCall($foreign->id, $other->id, ['remarks' => 'Confidencial']);

    $this->get("/calls?employee={$foreign->id}")
        ->assertInertia(fn ($page) => $page->where('selected', null));
});

it('denies the call panel to a user without the permission', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);

    $this->actingAs($plain)->get('/calls')->assertForbidden();
    $this->actingAs($plain)->post('/calls', [
        'employee_id' => Employee::factory()->create(['company_id' => $this->company->id])->id,
        'remarks' => 'x',
    ])->assertForbidden();
});

it('accepts a browser webm voice note (sniffed as video/webm)', function () {
    Storage::fake('local');
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    // A MediaRecorder webm blob is content-sniffed as video/webm, not audio/webm.
    $this->post('/calls', [
        'employee_id' => $employee->id,
        'remarks' => 'Voice note attached',
        'voice_note' => UploadedFile::fake()->create('note.webm', 120, 'video/webm'),
        'voice_note_label' => 'voice note',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $call = EmployeeCallLog::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect($call->voice_note_path)->not->toBeNull();
});

/*
 * The overview date range (2026-09): the ONE date filter now drives the month
 * overview; the selected worker's history is always FULL (the confusing second
 * per-worker range was removed).
 */

it('keeps the selected worker history FULL while the range drives the overview', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    logCall($employee->id, $this->company->id, ['called_at' => '2026-05-10 09:00:00', 'remarks' => 'May call']);
    logCall($employee->id, $this->company->id, ['called_at' => '2026-06-15 09:00:00', 'remarks' => 'June call']);
    logCall($employee->id, $this->company->id, ['called_at' => '2026-07-20 09:00:00', 'remarks' => 'July call']);

    // A June overview range does NOT trim the worker's history — all three show,
    // while the overview counts only the June call.
    $this->get("/calls?employee={$employee->id}&from=2026-06-01&to=2026-06-30")
        ->assertInertia(fn ($p) => $p->has('selected.calls', 3)
            ->where('overview.calls_made.count', 1)
            ->where('filters.from', '2026-06-01')
            ->where('filters.to', '2026-06-30'));
});

it('ignores a malformed date filter rather than erroring (falls back to the month)', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    logCall($employee->id, $this->company->id, ['called_at' => '2026-06-15 09:00:00']);

    // A malformed custom date is ignored → the overview falls back to the
    // month (effective bounds set, page renders, no error).
    $this->get("/calls?employee={$employee->id}&from=not-a-date&to=")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('selected.calls', 1)
            ->where('filters.range', 'month')
            ->where('filters.month', now()->format('Y-m')));
});

it('saves the call outcome (default connected; explicit no_answer honoured)', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    // Omitted → defaults to connected.
    $this->post('/calls', ['employee_id' => $employee->id, 'remarks' => 'Reached'])->assertRedirect();
    // Explicit no_answer.
    $this->post('/calls', ['employee_id' => $employee->id, 'remarks' => 'No pickup', 'call_outcome' => 'no_answer'])->assertRedirect();

    $calls = EmployeeCallLog::query()->orderBy('id')->get();
    expect($calls[0]->call_outcome)->toBe(CallOutcome::Connected)
        ->and($calls[1]->call_outcome)->toBe(CallOutcome::NoAnswer);
});

it('ships the outcome options + never leaks another company overview (tenancy)', function (): void {
    $mine = Employee::factory()->create(['company_id' => $this->company->id]);
    logCall($mine->id, $this->company->id, ['called_at' => now(), 'call_outcome' => 'connected']);

    // Another company's call must not appear in MY overview.
    $other = Company::factory()->create();
    $otherEmp = Employee::factory()->create(['company_id' => $other->id]);
    logCall($otherEmp->id, $other->id, ['called_at' => now(), 'call_outcome' => 'connected']);

    $this->get('/calls')
        ->assertInertia(fn ($p) => $p
            ->has('callOutcomes', 2)
            ->where('overview.calls_made.count', 1)      // only mine
            ->where('overview.connected.count', 1));
});
