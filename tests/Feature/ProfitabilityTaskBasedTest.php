<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\TaskProgress;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\ProductionTasks\TaskProgressService;
use App\Services\Reports\ProfitabilityService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

/** A worked attendance day on a project with a frozen labour total. */
function punchTaskDay(Company $company, Employee $employee, Project $project, string $date, float $labour): void
{
    Attendance::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'project_id' => $project->id,
        'date' => $date, 'status' => 'present', 'day_type' => 'full', 'hours_worked' => '8', 'total_amount' => (string) $labour,
    ]);
}

it('sources task-based revenue from task progress × client_rate, never unit_price', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'task_based']);
    // Internal cost 5, CLIENT rate 20 — only the client rate may bill.
    $task = ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id, 'unit_price' => '5', 'client_rate' => '20',
    ]);
    TaskProgress::factory()->create([
        'company_id' => $this->company->id, 'production_task_id' => $task->id, 'date' => '2026-06-01', 'quantity' => '10',
    ]);
    punchTaskDay($this->company, $this->employee, $project, '2026-06-01', 100);

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());

    expect($pnl['revenue'])->toBe(200.0)          // 10 × 20 (client_rate), NOT 10 × 5
        ->and($pnl['revenue_basis'])->toBe('task_based')
        ->and($pnl['labour_cost'])->toBe(100.0)
        ->and($pnl['profit'])->toBe(100.0);
});

it('builds the task-based daily P&L display: production sub-lines, effective rates, per-worker meters (Item 1)', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'task_based']);
    $task = ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'name' => 'Alicatado', 'unit' => 'm²', 'client_rate' => '13',
    ]);
    $w2 = Employee::factory()->forCompany($this->company)->create();

    // Two workers, a full 8 h day each (€60 labour each). Together 12 m² @ 13 €/m².
    punchTaskDay($this->company, $this->employee, $project, '2026-09-11', 60);
    punchTaskDay($this->company, $w2, $project, '2026-09-11', 60);
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'employee_id' => $this->employee->id, 'date' => '2026-09-11', 'quantity' => '6']);
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'employee_id' => $w2->id, 'date' => '2026-09-11', 'quantity' => '6']);

    $day = collect(app(ProfitabilityService::class)->dailyPnl($project->fresh())['days'])
        ->firstWhere('date', '2026-09-11');

    // Money is UNCHANGED (display redesign only): income 12×13=156, cost 120, profit 36.
    expect((float) $day['income'])->toBe(156.0)
        ->and((float) $day['labour'])->toBe(120.0)
        ->and((float) $day['profit'])->toBe(36.0)
        ->and((float) $day['hours'])->toBe(16.0);

    // Production sub-line: one task, 12 m² × 13 € = 156 €.
    expect($day['production'])->toHaveCount(1);
    expect($day['production'][0]['task'])->toBe('Alicatado')
        ->and($day['production'][0]['unit'])->toBe('m²')
        ->and((float) $day['production'][0]['quantity'])->toBe(12.0)
        ->and((float) $day['production'][0]['rate'])->toBe(13.0)
        ->and((float) $day['production'][0]['income'])->toBe(156.0);

    // Effective rates: 0.75 m²/h · billed 9.75 €/h · labour 7.50 €/h · margin 2.25 €/h.
    expect((float) $day['effective']['qty_per_hour'])->toBe(0.75)
        ->and($day['effective']['unit'])->toBe('m²')
        ->and((float) $day['effective']['per_hour_billed'])->toBe(9.75)
        ->and((float) $day['effective']['per_hour_labour'])->toBe(7.5)
        ->and((float) $day['effective']['per_hour_margin'])->toBe(2.25);

    // Per-worker line: their produced quantity + real cost, no artificial client split.
    expect((float) $day['workers'][0]['meters'])->toBe(6.0)
        ->and($day['workers'][0]['unit'])->toBe('m²')
        ->and((float) $day['workers'][0]['cost'])->toBe(60.0);
});

it('treats client-rejected rework as a penalty: billed once, cost still counts, profit drops (Item 2)', function (): void {
    $this->actingAs($this->admin); // the service fills task_progress.company_id from the active company
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'task_based']);
    $task = ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'name' => 'Alicatado', 'unit' => 'm²', 'client_rate' => '13', 'planned_quantity' => '12',
    ]);

    // Both days: the worker is present a full 8 h day (€120 labour each).
    punchTaskDay($this->company, $this->employee, $project, '2026-06-01', 120);
    punchTaskDay($this->company, $this->employee, $project, '2026-06-02', 120);

    $svc = app(TaskProgressService::class);
    // Day 1: 12 m² produced (billable).
    $svc->logWork($task, ['date' => '2026-06-01', 'quantity' => 12, 'employee_ids' => [$this->employee->id]]);
    // Day 2: client rejected it → redo the SAME 12 m² as REWORK (must not bill again).
    $svc->logWork($task, ['date' => '2026-06-02', 'quantity' => 12, 'employee_ids' => [$this->employee->id], 'is_rework' => true, 'notes' => 'Cliente rechazó el acabado']);

    // completed_quantity stays 12 (a redo, not new completion — never 24).
    expect((float) $task->fresh()->completed_quantity)->toBe(12.0);

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());
    // Billed ONCE: 12 × 13 = 156 (never 24 × 13). Cost is BOTH days (240 — the
    // rework day's labour is the penalty). Profit 156 − 240 = −84.
    expect($pnl['revenue'])->toBe(156.0)
        ->and($pnl['labour_cost'])->toBe(240.0)
        ->and($pnl['profit'])->toBe(-84.0);

    // Daily view: the normal day earns 156; the rework day earns 0 but carries a
    // "not billed" rework line and its full labour (−120 profit = the penalty).
    $days = collect(app(ProfitabilityService::class)->dailyPnl($project->fresh())['days']);
    $normal = $days->firstWhere('date', '2026-06-01');
    $rework = $days->firstWhere('date', '2026-06-02');

    expect((float) $normal['income'])->toBe(156.0)
        ->and($normal['rework'])->toBe([])
        ->and((float) $rework['income'])->toBe(0.0)
        ->and((float) $rework['labour'])->toBe(120.0)
        ->and((float) $rework['profit'])->toBe(-120.0);
    expect($rework['rework'])->toHaveCount(1);
    expect($rework['rework'][0]['task'])->toBe('Alicatado')
        ->and((float) $rework['rework'][0]['quantity'])->toBe(12.0);
    // The normal day's production still bills correctly (rework excluded from it).
    expect($normal['production'])->toHaveCount(1)
        ->and((float) $normal['production'][0]['income'])->toBe(156.0);
});

it('reads task-based revenue as neutral/not-configured when no task has a client_rate', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'task_based']);
    $task = ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id, 'unit_price' => '5', 'client_rate' => null,
    ]);
    TaskProgress::factory()->create([
        'company_id' => $this->company->id, 'production_task_id' => $task->id, 'date' => '2026-06-01', 'quantity' => '10',
    ]);
    punchTaskDay($this->company, $this->employee, $project, '2026-06-01', 100);

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());

    expect($pnl['revenue'])->toBe(0.0)
        ->and($pnl['revenue_basis'])->toBe('not_configured')
        ->and($pnl['health'])->toBe('neutral'); // never a fake −100% loss
});

it('leaves the hourly revenue path unchanged (regression guard)', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20']);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'project_id' => $project->id,
        'date' => '2026-06-01', 'status' => 'present', 'day_type' => 'hourly', 'hours_worked' => '8', 'total_amount' => '100',
    ]);

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());

    expect($pnl['revenue'])->toBe(160.0)          // 8h × 20, unchanged
        ->and($pnl['revenue_basis'])->toBe('hourly');
});

it('nudges a task-based project with attendance but no task progress (Change: reminder)', function (): void {
    Notification::fake();
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'task_based']);
    // Worked recently, but NO task progress logged.
    punchTaskDay($this->company, $this->employee, $project, now()->subDays(2)->toDateString(), 100);

    $this->artisan('notifications:scan')->assertSuccessful();

    Notification::assertSentTo($this->admin, SystemNotification::class);
    expect($project->fresh()->task_reminder_at)->not->toBeNull(); // cadence stamped
});

it('does NOT nudge when task progress has been logged in the window', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'task_based']);
    $task = ProductionTask::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'client_rate' => '20']);
    punchTaskDay($this->company, $this->employee, $project, now()->subDays(2)->toDateString(), 100);
    TaskProgress::factory()->create([
        'company_id' => $this->company->id, 'production_task_id' => $task->id, 'date' => now()->subDays(1)->toDateString(), 'quantity' => '5',
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();

    // The task nudge stamps task_reminder_at only when IT fires — a null stamp
    // proves it stayed silent (independent of other sweeps' notifications).
    expect($project->fresh()->task_reminder_at)->toBeNull();
});

it('does not re-nudge within the cadence window (cadence guard)', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'task_based', 'task_reminder_at' => now()->subDay(),
    ]);
    punchTaskDay($this->company, $this->employee, $project, now()->subDays(2)->toDateString(), 100);
    $before = $project->fresh()->task_reminder_at;

    $this->artisan('notifications:scan')->assertSuccessful();

    // Stamp unchanged → it did not re-fire (nudged less than 7 days ago).
    expect($project->fresh()->task_reminder_at->toDateTimeString())->toBe($before->toDateTimeString());
});
