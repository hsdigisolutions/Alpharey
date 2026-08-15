<?php

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
 * Stats bar.
 */
it('reports the stats bar figures', function (): void {
    $a = Employee::factory()->create(['company_id' => $this->company->id]);
    $b = Employee::factory()->create(['company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id]); // never called

    logCall($a->id, $this->company->id, ['called_at' => now(), 'follow_up_date' => now()->toDateString()]);
    logCall($b->id, $this->company->id, ['called_at' => now()]);

    $this->get('/calls')
        ->assertInertia(fn ($page) => $page
            ->where('stats.calls_today', 2)
            ->where('stats.pending_follow_ups', 1)
            ->where('stats.not_contacted_this_week', 1));
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
