<?php

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Screen 02 — the Welcome company cards must show LIVE counts, not the Phase-1
 * "—" placeholders. Counts are computed across all companies (Super Admin view)
 * with the tenant scope dropped, then keyed back per company.
 */
beforeEach(function (): void {
    // Deterministic name order so companies.0 is 'Aardvark' (orderBy name).
    $this->a = Company::factory()->create(['name' => 'Aardvark Co']);
    $this->b = Company::factory()->create(['name' => 'Bravo Co']);
    $this->sa = User::factory()->superAdmin()->create();
});

it('shows live employee and project counts per company', function (): void {
    // Company A: 2 active employees (+1 inactive, must NOT count), 1 active
    // project (+1 completed, must NOT count).
    Employee::factory()->forCompany($this->a)->count(2)->create(['active' => true]);
    Employee::factory()->forCompany($this->a)->inactive()->create();
    Project::factory()->forCompany($this->a)->create(['status' => ProjectStatus::Active]);
    Project::factory()->forCompany($this->a)->create(['status' => ProjectStatus::Completed]);

    // Company B: 1 active employee.
    Employee::factory()->forCompany($this->b)->create(['active' => true]);

    $this->actingAs($this->sa)->get('/welcome')->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('companies.0.name', 'Aardvark Co')
        ->where('companies.0.employees_count', 2)
        ->where('companies.0.projects_count', 1)
        ->where('companies.1.employees_count', 1)
        ->where('stats.total_employees', 3)
        ->where('stats.active_projects', 1));
});

it('no longer renders the counts as null placeholders', function (): void {
    Employee::factory()->forCompany($this->a)->create(['active' => true]);

    $this->actingAs($this->sa)->get('/welcome')->assertInertia(fn (Assert $page) => $page
        ->where('companies.0.employees_count', fn ($v) => $v !== null)
        ->where('companies.0.deployed_count', 0)
        ->where('stats.active_deployments', fn ($v) => $v !== null));
});
