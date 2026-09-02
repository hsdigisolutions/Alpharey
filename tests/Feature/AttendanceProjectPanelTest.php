<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Feature 1 — the attendance grid's project roster panel: the assigned workers
 * for a chosen project + date, present AND absent, with a present-of-assigned
 * tally.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->project = Project::factory()->forCompany($this->company)->create(['name' => 'Reforma Madrid']);
});

function assignWorker(Project $project, Employee $e): void
{
    $r = new ProjectEmployeeRate(['employee_id' => $e->id, 'wage_type' => 'hourly', 'project_rate' => '20']);
    $r->project_id = $project->id;
    $r->company_id = $project->company_id;
    $r->save();
}

it('lists assigned workers present and absent with a present tally', function (): void {
    $present = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Carlos']);
    $absent = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Ahmad']);
    assignWorker($this->project, $present);
    assignWorker($this->project, $absent);

    $date = now()->toDateString();
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $present->id, 'project_id' => $this->project->id,
        'date' => $date, 'status' => 'present', 'hours_worked' => '8',
    ]);

    $this->actingAs($this->admin)
        ->get("/attendance?project={$this->project->id}&panel_date={$date}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('projectPanel.assigned', 2)
            ->where('projectPanel.present', 1)
            ->has('projectPanel.rows', 2));
});

it('includes a worker present via attendance even with no rate roster', function (): void {
    // No ProjectEmployeeRate, no deployment — the worker is "assigned" to the
    // site purely by having worked there (how this client staffs projects).
    $worker = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Abdullah']);
    $date = now()->toDateString();
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $this->project->id,
        'date' => $date, 'status' => 'present', 'hours_worked' => '8',
    ]);

    $this->actingAs($this->admin)
        ->get("/attendance?project={$this->project->id}&panel_date={$date}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('projectPanel.assigned', 1)
            ->where('projectPanel.present', 1)
            ->has('projectPanel.rows', 1));
});

it('ships no panel when no project is selected', function (): void {
    $this->actingAs($this->admin)->get('/attendance')
        ->assertInertia(fn (Assert $p) => $p->where('projectPanel', null));
});
