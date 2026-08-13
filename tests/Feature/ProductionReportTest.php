<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\ProductionTasks\ProductionReportService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    // The report service reads company-scoped models, so it needs an active
    // company context (as the controller always has).
    $this->actingAs(User::factory()->companyAdmin()->forCompany($this->company)->create());
    $this->project = Project::factory()->forCompany($this->company)->create();
    $this->service = app(ProductionReportService::class);
    Carbon::setTestNow('2026-08-13');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('computes planned vs actual with an estimated finish from the 7-day rate', function (): void {
    $task = ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
        'planned_quantity' => 500, 'completed_quantity' => 320, 'unit' => 'm2',
    ]);

    // 210 produced across the last 7 days → avg 30/day; remaining 180 → 6 days → 19 Aug.
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'date' => '2026-08-11', 'quantity' => 100]);
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'date' => '2026-08-12', 'quantity' => 110]);
    // An old row outside the window must NOT lift the average.
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'date' => '2026-07-01', 'quantity' => 999]);

    $report = $this->service->plannedVsActual($this->project);
    $row = $report['rows'][0];

    expect($row['planned'])->toBe(500.0)
        ->and($row['done'])->toBe(320.0)
        ->and($row['remaining'])->toBe(180.0)
        ->and($row['progress'])->toBe(64.0)
        ->and($row['avg_daily'])->toBe(30.0)
        ->and($row['est_finish'])->toBe('2026-08-19')
        ->and($report['totals']['remaining'])->toBe(180.0);
});

it('marks a completed task as done and a stalled one as no-estimate', function (): void {
    ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
        'name' => 'Finished', 'planned_quantity' => 100, 'completed_quantity' => 100,
    ]);
    // Remaining work but zero recent production → no estimate.
    ProductionTask::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
        'name' => 'Stalled', 'planned_quantity' => 100, 'completed_quantity' => 20,
    ]);

    $rows = collect($this->service->plannedVsActual($this->project)['rows']);

    expect($rows->firstWhere('name', 'Finished')['est_finish'])->toBe('done')
        ->and($rows->firstWhere('name', 'Stalled')['est_finish'])->toBeNull();
});

it('aggregates per-worker productivity, heaviest first', function (): void {
    $task = ProductionTask::factory()->create(['company_id' => $this->company->id, 'project_id' => $this->project->id]);
    $a = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Ana']);
    $b = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Beto']);

    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'employee_id' => $a->id, 'date' => '2026-08-12', 'quantity' => 40]);
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'employee_id' => $b->id, 'date' => '2026-08-12', 'quantity' => 90]);
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $task->id, 'employee_id' => $b->id, 'date' => '2026-07-01', 'quantity' => 10]);

    $rows = $this->service->perWorker($this->project);

    expect($rows[0]['employee'])->toBe('Beto') // 100 total, heaviest first
        ->and($rows[0]['total'])->toBe(100.0)
        ->and($rows[0]['last7'])->toBe(90.0)  // the July row is outside the window
        ->and($rows[1]['employee'])->toBe('Ana')
        ->and($rows[1]['total'])->toBe(40.0);
});

it('builds a daily trend series per category', function (): void {
    $civil = ProductionTask::factory()->create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'category' => 'civil']);
    $plumbing = ProductionTask::factory()->create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'category' => 'plumbing']);

    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $civil->id, 'date' => '2026-08-10', 'quantity' => 20]);
    TaskProgress::factory()->create(['company_id' => $this->company->id, 'production_task_id' => $plumbing->id, 'date' => '2026-08-11', 'quantity' => 15]);

    $trend = $this->service->trend($this->project);

    expect($trend['labels'])->toBe(['2026-08-10', '2026-08-11'])
        ->and($trend['series'])->toHaveCount(2);

    $civilSeries = collect($trend['series'])->firstWhere('category', 'civil');
    expect($civilSeries['data'])->toBe([20.0, 0.0]); // qty on day 1, nothing on day 2
});
