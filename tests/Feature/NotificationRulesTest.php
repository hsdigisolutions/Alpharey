<?php

use App\Enums\BillingMethod;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\NotificationRoleRule;
use App\Models\Project;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Notifications\NotificationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
});

it('falls back to the coded default when no rule is stored', function (): void {
    $rules = app(NotificationRules::class);

    // Deployment events default to SA + Company Admin; payroll only to CA.
    expect($rules->allows(NotificationType::DeploymentEvent, UserRole::SuperAdmin))->toBeTrue()
        ->and($rules->allows(NotificationType::PayrollReady, UserRole::SuperAdmin))->toBeFalse()
        ->and($rules->allows(NotificationType::PayrollReady, UserRole::Admin))->toBeTrue()
        ->and($rules->allows(NotificationType::PayrollReady, UserRole::Manager))->toBeFalse();
});

it('lets a stored rule override the default', function (): void {
    NotificationRoleRule::query()->create([
        'notification_type' => NotificationType::PayrollReady->value,
        'role' => UserRole::Admin->value,
        'enabled' => false,
    ]);

    expect(app(NotificationRules::class)->allows(NotificationType::PayrollReady, UserRole::Admin))->toBeFalse();
});

it('resolves recipients scoped to the company plus super admins', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id, 'active' => true]);
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null, 'active' => true]);
    // An admin of ANOTHER company must not receive this company's alert.
    $other = User::factory()->create(['role' => UserRole::Admin, 'company_id' => Company::factory()->create()->id]);

    $recipients = app(NotificationRules::class)->recipients(NotificationType::DeploymentEvent, $this->company->id);
    $ids = $recipients->pluck('id');

    expect($ids)->toContain($admin->id)
        ->and($ids)->toContain($sa->id)
        ->and($ids)->not->toContain($other->id);
});

it('reaches users assigned into the company via the user_company pivot', function (): void {
    // Primary company elsewhere, but assigned INTO this company on the pivot
    // — they can act in it, so its alerts are theirs too.
    $assigned = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => Company::factory()->create()->id,
        'active' => true,
    ]);
    DB::table('user_company')->insert([
        'user_id' => $assigned->id,
        'company_id' => $this->company->id,
        'created_at' => now(),
    ]);

    $ids = app(NotificationRules::class)
        ->recipients(NotificationType::DeploymentEvent, $this->company->id)
        ->pluck('id');

    expect($ids)->toContain($assigned->id);
});

it('sends nothing when every role is disabled for a type', function (): void {
    foreach (UserRole::cases() as $role) {
        NotificationRoleRule::query()->create([
            'notification_type' => NotificationType::PayrollReady->value,
            'role' => $role->value,
            'enabled' => false,
        ]);
    }

    User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);

    expect(app(NotificationRules::class)->recipients(NotificationType::PayrollReady, $this->company->id))->toHaveCount(0);
});

/**
 * The reference sender end-to-end: completing a deployment notifies the
 * matrix-selected recipients, and honours a rule that switches them off.
 */
it('notifies on a deployment lifecycle event through the matrix', function (): void {
    Notification::fake();

    $home = Company::factory()->create();
    $host = $this->company;
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $host->id]);
    $homeAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $home->id]);

    $employee = Employee::factory()->create(['company_id' => $home->id]);
    $project = Project::factory()->create(['company_id' => $host->id]);
    $deployment = EmployeeDeployment::query()->create([
        'employee_id' => $employee->id,
        'home_company_id' => $home->id,
        'host_company_id' => $host->id,
        'project_id' => $project->id,
        'deployment_start' => now()->subDay()->toDateString(),
        'billing_method' => BillingMethod::OptionA,
        'status' => DeploymentStatus::Active,
    ]);

    // Acting as the host admin, cancel the deployment.
    $this->actingAs($hostAdmin)->post("/deployments/{$deployment->id}/cancel")->assertRedirect();

    // The HOME company's admin is a recipient (the worker belongs to them).
    Notification::assertSentTo($homeAdmin, SystemNotification::class);
});

it('respects a disabled rule when a deployment event fires', function (): void {
    Notification::fake();

    // Turn deployment events off for everyone.
    foreach (UserRole::cases() as $role) {
        NotificationRoleRule::query()->create([
            'notification_type' => NotificationType::DeploymentEvent->value,
            'role' => $role->value,
            'enabled' => false,
        ]);
    }

    $home = Company::factory()->create();
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    User::factory()->create(['role' => UserRole::Admin, 'company_id' => $home->id]);

    $employee = Employee::factory()->create(['company_id' => $home->id]);
    $project = Project::factory()->create(['company_id' => $this->company->id]);
    $deployment = EmployeeDeployment::query()->create([
        'employee_id' => $employee->id,
        'home_company_id' => $home->id,
        'host_company_id' => $this->company->id,
        'project_id' => $project->id,
        'deployment_start' => now()->subDay()->toDateString(),
        'billing_method' => BillingMethod::OptionA,
        'status' => DeploymentStatus::Active,
    ]);

    $this->actingAs($hostAdmin)->post("/deployments/{$deployment->id}/cancel")->assertRedirect();

    Notification::assertNothingSent();
});

it('lets a Super Admin save the matrix but forbids others', function (): void {
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);
    $ca = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);

    $payload = ['matrix' => [
        ['type' => NotificationType::PayrollReady->value, 'roles' => ['admin' => false]],
    ]];

    $this->actingAs($ca)->put('/admin/settings/notifications', $payload)->assertForbidden();

    $this->actingAs($sa)->put('/admin/settings/notifications', $payload)->assertRedirect();
    expect(NotificationRoleRule::query()->where('notification_type', 'payroll_ready')->where('role', 'admin')->value('enabled'))
        ->toBe(false);
});

it('reports system health to a super admin on the settings page', function (): void {
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);

    $this->actingAs($sa)
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('systemHealth.database')
            ->has('notificationMatrix')
            ->where('systemHealth.database.status', 'ok'));
});
