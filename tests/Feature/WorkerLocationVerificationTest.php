<?php

use App\Enums\DeploymentStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Settings\SettingsService;
use App\Services\Workers\WorkerAccountService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Worker location verification (Phase 1). A check-in now carries the project the
 * worker is on and its distance from that project's site. GPS stays EVIDENCE,
 * never a gate: the distance is measured, an off-site punch is flagged to the
 * admins, but the punch always stands.
 */
beforeEach(function (): void {
    Storage::fake('local');
    // 2026-08-10 is a Monday (weekends need a weekend offer to punch).
    $this->travelTo('2026-08-10 09:00');

    $this->company = Company::factory()->create();
    $this->employee = Employee::factory()->forCompany($this->company)->privacyAcknowledged()->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);

    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($admin);
    app(WorkerAccountService::class)->grant($this->employee, 'obrero@example.com', 'site-pass-123');
    auth()->logout();

    $this->worker = $this->employee->fresh()->user;

    // Madrid coordinates for the project site; the worker fixes are relative.
    $this->siteLat = 40.4168;
    $this->siteLng = -3.7038;
});

afterEach(fn () => $this->travelBack());

/** Create an ACTIVE project (optionally with coordinates) and assign the worker. */
function assignedProject(Company $company, Employee $employee, ?float $lat, ?float $lng, int $radius = 500): Project
{
    $project = Project::factory()->forCompany($company)->create([
        'status' => 'active',
        'latitude' => $lat,
        'longitude' => $lng,
        'geofence_radius' => $radius,
    ]);

    $rate = new ProjectEmployeeRate(['employee_id' => $employee->id, 'wage_type' => 'hourly', 'project_rate' => '20']);
    $rate->company_id = $company->id;
    $rate->project_id = $project->id;
    $rate->save();

    return $project;
}

it('saves the distance and bands an accurate on-site check-in', function (): void {
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->project_id)->toBe($project->id)
        ->and((float) $row->distance_from_project)->toBeLessThan(5.0); // essentially on the point
});

it('stores no distance when the fix is too coarse to trust', function (): void {
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    // Accuracy worse than the 1000 m limit: a ±50 km fix cannot anchor a distance.
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 50000, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->distance_from_project)->toBeNull();
});

it('stores no distance when the project has no coordinates', function (): void {
    $project = assignedProject($this->company, $this->employee, null, null);

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->distance_from_project)->toBeNull();
});

it('alerts the admins when a worker checks in off site', function (): void {
    Notification::fake();
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    // ~4.2 km east of the site — beyond the 2000 m default off-site threshold.
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => -3.6538, 'accuracy' => 15, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect();

    Notification::assertSentTo(
        User::where('role', 'admin')->get(),
        SystemNotification::class,
        fn (SystemNotification $n) => ($n->toDatabase($this->worker)['type'] ?? null) === 'worker_off_site',
    );
});

it('does not alert when the worker checks in on site', function (): void {
    Notification::fake();
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect();

    Notification::assertNotSentTo(
        User::where('role', 'admin')->get(),
        SystemNotification::class,
    );
});

it('rejects a check-in into a project the worker is not assigned to', function (): void {
    // An active project the worker has NO rate/deployment link to.
    $other = Project::factory()->forCompany($this->company)->create([
        'status' => 'active', 'latitude' => $this->siteLat, 'longitude' => $this->siteLng,
    ]);

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => $other->id,
    ])->assertSessionHasErrors('project_id');

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

it('accepts a project_id sent as a string (the picker emits strings)', function (): void {
    // The worker PWA's VSelect emits the chosen option value as a STRING; the
    // server must still resolve it to the integer project and price the row.
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => (string) $project->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->project_id)->toBe($project->id);
});

it('allows a project-less check-in with a null distance', function (): void {
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($row->project_id)->toBeNull()
        ->and($row->distance_from_project)->toBeNull();
});

it('ships the worker their assigned and deployed projects on the home screen', function (): void {
    $assigned = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    // Deployed INTO another company's project.
    $host = Company::factory()->create();
    $hostProject = Project::factory()->forCompany($host)->create(['status' => 'active', 'name' => 'Host Site']);
    EmployeeDeployment::factory()->create([
        'employee_id' => $this->employee->id,
        'home_company_id' => $this->company->id,
        'host_company_id' => $host->id,
        'project_id' => $hostProject->id,
        'status' => DeploymentStatus::Active,
    ]);

    $this->actingAs($this->worker)->get('/worker')
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignedProjects', 2)
            ->where('assignedProjects.0.name', fn ($name) => in_array($name, [$assigned->name, 'Host Site'], true)));
});

it('carries the distance band on the admin attendance grid cell', function (): void {
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect();

    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($admin)->get('/attendance?month=2026-08')
        ->assertInertia(fn (Assert $page) => $page
            ->where("grid.{$this->employee->id}.10.distance_band", 'on_site'));
});

it('will not let a worker check into another company project', function (): void {
    $other = Company::factory()->create();
    $foreign = Project::factory()->forCompany($other)->create(['status' => 'active', 'latitude' => $this->siteLat, 'longitude' => $this->siteLng]);

    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => $this->siteLng, 'accuracy' => 15, 'denied' => false,
        'project_id' => $foreign->id,
    ])->assertSessionHasErrors('project_id');

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

it('honours a wider per-company off-site threshold before alerting', function (): void {
    Notification::fake();
    // Raise the off-site threshold to 10 km for this company.
    app(SettingsService::class)->set("attendance.off_site_alert_distance.{$this->company->id}", 10000);
    $project = assignedProject($this->company, $this->employee, $this->siteLat, $this->siteLng);

    // ~4.2 km away — off site at the 2000 m default, but WITHIN the 10 km setting.
    $this->actingAs($this->worker)->post('/worker/check-in', [
        'lat' => $this->siteLat, 'lng' => -3.6538, 'accuracy' => 15, 'denied' => false,
        'project_id' => $project->id,
    ])->assertRedirect();

    Notification::assertNotSentTo(User::where('role', 'admin')->get(), SystemNotification::class);
});
