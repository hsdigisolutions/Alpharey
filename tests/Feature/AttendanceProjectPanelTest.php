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

it('shows the manual check-in/out time when there is no PWA punch, and exports the panel', function (): void {
    $worker = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Abdullah']);
    assignWorker($this->project, $worker);
    $date = now()->toDateString();

    $a = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $this->project->id,
        'date' => $date, 'status' => 'present', 'day_type' => 'full',
        'check_in' => '09:00', 'check_out' => '17:00', 'hours_worked' => '8',
    ]);
    // No PWA punch datetimes (a clerk/admin entry).
    $a->forceFill(['check_in_at' => null, 'check_out_at' => null])->saveQuietly();

    $this->actingAs($this->admin)
        ->get("/attendance?project={$this->project->id}&panel_date={$date}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('projectPanel.rows.0.check_in', '09:00')
            ->where('projectPanel.rows.0.check_out', '17:00'));

    // Export both formats.
    $q = "project={$this->project->id}&panel_date={$date}";
    $this->actingAs($this->admin)->get("/attendance/panel-export?{$q}&format=excel")->assertOk();
    $this->actingAs($this->admin)->get("/attendance/panel-export?{$q}&format=pdf")->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'module' => 'attendance']);
});

it('ships no panel when no project is selected', function (): void {
    $this->actingAs($this->admin)->get('/attendance')
        ->assertInertia(fn (Assert $p) => $p->where('projectPanel', null));
});
