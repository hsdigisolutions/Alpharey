<?php

use App\Enums\AdvanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\WageType;
use App\Enums\WorkerExpenseStatus;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCallLog;
use App\Models\Invoice;
use App\Models\LeaveCategory;
use App\Models\Project;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSession;
use App\Models\WorkerExpense;
use App\Notifications\SystemNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

// ── Helpers ──────────────────────────────────────────────────────────────────

function companyWithAdmin(): array
{
    $company = Company::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $company->id, 'active' => true]);

    return [$company, $admin];
}

function linkedWorker(Company $company): array
{
    $user = User::factory()->worker()->for($company)->create();
    $employee = Employee::factory()->for($company)->create([
        'user_id' => $user->id,
        'wage_type' => WageType::Daily,
        'daily_wage' => '50',
    ]);

    return [$user, $employee];
}

// ── Event-driven triggers ────────────────────────────────────────────────────

it('notifies admins when a leave request is filed', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    $employee = Employee::factory()->for($company)->create();
    $category = LeaveCategory::factory()->create(['key' => 'annual', 'is_paid' => true, 'default_allocation' => '20']);
    $monday = now()->startOfMonth()->next('Monday');

    $this->actingAs($admin)->post('/leave', [
        'employee_id' => $employee->id,
        'leave_category_id' => $category->id,
        'start_date' => $monday->toDateString(),
        'end_date' => $monday->copy()->addDays(2)->toDateString(),
        'total_days' => '3',
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($admin, SystemNotification::class);
});

it('notifies the worker when their advance is decided', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    [$workerUser, $employee] = linkedWorker($company);

    $advance = Advance::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => AdvanceStatus::Pending,
    ]);

    $this->actingAs($admin)->post("/advances/{$advance->id}/decide", [
        'status' => AdvanceStatus::Approved->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($workerUser, SystemNotification::class);
});

it('notifies the worker when their expense is approved', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    [$workerUser, $employee] = linkedWorker($company);

    $expense = WorkerExpense::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => WorkerExpenseStatus::Pending,
    ]);

    $this->actingAs($admin)->post("/worker-expenses/{$expense->id}/approve")
        ->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($workerUser, SystemNotification::class);
});

it('notifies a super admin on a fully-paid invoice', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    // InvoicePaid is a Super-Admin-visible sign-off event.
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null, 'active' => true]);

    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'type' => InvoiceType::Sale,
        'status' => InvoiceStatus::Sent,
        'subtotal' => '100', 'total' => '100', 'paid_amount' => '0',
        'payment_status' => PaymentStatus::Unpaid,
    ]);

    $this->actingAs($admin)->post("/invoices/{$invoice->id}/payments", [
        'amount' => '100', 'payment_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($sa, SystemNotification::class);
});

it('notifies invited workers when a weekend offer is published', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    [$workerUser, $employee] = linkedWorker($company);
    $project = Project::factory()->for($company)->create();
    $saturday = now()->next(Carbon::SATURDAY);

    $this->actingAs($admin)->post('/weekend-offers', [
        'offer_date' => $saturday->toDateString(),
        'project_id' => $project->id,
        'weekend_rate_type' => 'x1.5',
        'invited_employee_ids' => [$employee->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($workerUser, SystemNotification::class);
});

// ── Time-based sweep (notifications:scan) ────────────────────────────────────

it('notifies admins about an overdue unpaid invoice, only once', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();

    Invoice::factory()->create([
        'company_id' => $company->id,
        'type' => InvoiceType::Sale,
        'due_date' => now()->subDays(3)->toDateString(),
        'total' => '500', 'paid_amount' => '0',
        'payment_status' => PaymentStatus::Unpaid,
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertSentToTimes($admin, SystemNotification::class, 1);

    // A second sweep must not re-alert (overdue_notified_at is set).
    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertSentToTimes($admin, SystemNotification::class, 1);
});

it('reminds admins about a project worked this month but not invoiced, once per cadence', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    $project = Project::factory()->for($company)->create();
    $employee = Employee::factory()->for($company)->create();

    Attendance::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'project_id' => $project->id, 'date' => now()->startOfMonth()->toDateString(),
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertSentToTimes($admin, SystemNotification::class, 1);

    // Second sweep is inside the cadence window — no re-reminder.
    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertSentToTimes($admin, SystemNotification::class, 1);
});

it('does not remind when the project already has a sale invoice this month', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    $project = Project::factory()->for($company)->create();
    $employee = Employee::factory()->for($company)->create();

    Attendance::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'project_id' => $project->id, 'date' => now()->startOfMonth()->toDateString(),
    ]);
    Invoice::factory()->create([
        'company_id' => $company->id, 'type' => InvoiceType::Sale,
        'project_id' => $project->id, 'invoice_date' => now()->startOfMonth()->toDateString(),
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertNotSentTo($admin, SystemNotification::class);
});

it('notifies the caller when a call follow-up is due today', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    $employee = Employee::factory()->for($company)->create();

    $log = new EmployeeCallLog([
        'called_at' => now(),
        'follow_up_date' => now()->toDateString(),
    ]);
    $log->company_id = $company->id;
    $log->employee_id = $employee->id;
    $log->called_by = $admin->id;
    $log->save();

    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertSentTo($admin, SystemNotification::class);
});

it('notifies admins about a vehicle out more than 24 hours', function (): void {
    Notification::fake();
    [$company, $admin] = companyWithAdmin();
    [, $employee] = linkedWorker($company);
    $vehicle = Vehicle::factory()->for($company)->create();

    $session = new VehicleSession([
        'vehicle_id' => $vehicle->id,
        'employee_id' => $employee->id,
        'taken_at' => now()->subHours(30),
        'starting_mileage' => 1000,
    ]);
    $session->company_id = $company->id;
    $session->save();

    $this->artisan('notifications:scan')->assertSuccessful();
    Notification::assertSentToTimes($admin, SystemNotification::class, 1);
});

// ── The /notifications page ──────────────────────────────────────────────────

it('renders the notifications page with the presented items', function (): void {
    [$company, $admin] = companyWithAdmin();
    $admin->notify(new SystemNotification([
        'type' => 'invoice_overdue',
        'title_es' => 'Factura vencida', 'title_en' => 'Overdue invoice',
        'entity' => 'F1-1', 'url' => '/invoices',
    ]));

    $this->actingAs($admin)->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Notifications/Index')
            ->where('unread', 1)
            ->has('items', 1)
            ->where('items.0.icon', 'invoices')
            ->where('items.0.category', 'invoices'));
});

it('deletes only the read notifications', function (): void {
    [$company, $admin] = companyWithAdmin();
    $admin->notify(new SystemNotification([
        'type' => 'invoice_overdue', 'title_es' => 'A', 'title_en' => 'A', 'url' => null,
    ]));
    $admin->notify(new SystemNotification([
        'type' => 'invoice_paid', 'title_es' => 'B', 'title_en' => 'B', 'url' => null,
    ]));

    // Mark the first read, then clear read ones.
    $admin->unreadNotifications->first()?->markAsRead();

    $this->actingAs($admin)->post('/notifications/delete-read')->assertRedirect();

    expect($admin->fresh()->notifications()->count())->toBe(1);
});
