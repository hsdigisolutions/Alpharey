<?php

use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::CompanyAdmin,
        'company_id' => $this->company->id,
    ]);
});

it('renders the dashboard for a company admin', function (): void {
    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('data.kpis'));
});

it('sends a Super Admin with no company selected to Welcome', function (): void {
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);

    $this->actingAs($sa)->get('/dashboard')->assertRedirect(route('welcome'));
});

it('counts only the active employees of the selected company', function (): void {
    Employee::factory()->count(3)->create(['company_id' => $this->company->id, 'active' => true]);
    Employee::factory()->create(['company_id' => $this->company->id, 'active' => false]);
    // another company's employees must not leak into the count
    $other = Company::factory()->create();
    Employee::factory()->count(5)->create(['company_id' => $other->id, 'active' => true]);

    $this->actingAs($this->admin);
    $data = app(DashboardService::class)->for($this->company->id);

    expect($data['kpis']['active_employees'])->toBe(3);
});

it('sums pending sales invoices for the money KPI, ignoring paid ones', function (): void {
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'type' => InvoiceType::Sale,
        'payment_status' => PaymentStatus::Unpaid, 'total' => '1000',
    ]);
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'type' => InvoiceType::Sale,
        'payment_status' => PaymentStatus::Paid, 'total' => '500',
    ]);

    $this->actingAs($this->admin);
    $data = app(DashboardService::class)->for($this->company->id);

    expect($data['kpis']['pending_invoices_eur'])->toBe(1000.0);
});

it('breaks projects down by status for the donut', function (): void {
    Project::factory()->count(2)->create(['company_id' => $this->company->id, 'status' => ProjectStatus::Active]);
    Project::factory()->create(['company_id' => $this->company->id, 'status' => ProjectStatus::Completed]);

    $this->actingAs($this->admin);
    $data = app(DashboardService::class)->for($this->company->id);

    expect($data['charts']['project_status']['active'])->toBe(2)
        ->and($data['charts']['project_status']['completed'])->toBe(1)
        ->and($data['charts']['project_status']['cancelled'])->toBe(0);
});

it('gives the revenue chart six months of labels', function (): void {
    $this->actingAs($this->admin);
    $data = app(DashboardService::class)->for($this->company->id);

    expect($data['charts']['revenue_vs_expenses']['labels'])->toHaveCount(6)
        ->and($data['charts']['attendance_trend']['labels'])->toHaveCount(30);
});

it('caches the payload per company and forgets on demand', function (): void {
    $this->actingAs($this->admin);
    $service = app(DashboardService::class);

    $service->for($this->company->id);
    expect(Cache::has("dashboard:{$this->company->id}"))->toBeTrue();

    $service->forget($this->company->id);
    expect(Cache::has("dashboard:{$this->company->id}"))->toBeFalse();
});

it('denies the dashboard to a guest', function (): void {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
