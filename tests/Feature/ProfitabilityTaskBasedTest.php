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
