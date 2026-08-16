<?php

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('ships project summary stats (total/active/completed/on_hold)', function (): void {
    Project::factory()->count(2)->forCompany($this->company)->create(['status' => ProjectStatus::Active]);
    Project::factory()->forCompany($this->company)->create(['status' => ProjectStatus::Completed]);
    Project::factory()->forCompany($this->company)->create(['status' => ProjectStatus::OnHold]);

    $this->actingAs($this->admin)->get('/projects')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 4)
            ->where('stats.active', 2)
            ->where('stats.completed', 1)
            ->where('stats.on_hold', 1));
});

it('ships client summary stats (total/active/inactive)', function (): void {
    Client::factory()->count(2)->create(['active' => true]);
    Client::factory()->create(['active' => false]);

    $this->actingAs($this->admin)->get('/clients')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 3)
            ->where('stats.active', 2)
            ->where('stats.inactive', 1));
});

it('ships vendor summary stats (total/active/inactive)', function (): void {
    Vendor::factory()->count(3)->create(['active' => true]);
    Vendor::factory()->create(['active' => false]);

    $this->actingAs($this->admin)->get('/vendors')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 4)
            ->where('stats.active', 3)
            ->where('stats.inactive', 1));
});

it('ships invoice summary stats with counts and euro totals for the sale tab', function (): void {
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'status' => InvoiceStatus::Draft, 'total' => '100']);
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'status' => InvoiceStatus::Sent, 'total' => '200']);
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'status' => InvoiceStatus::Paid, 'total' => '300']);
    // An expense-type invoice must NOT leak into the sale-tab stats.
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Expense, 'status' => InvoiceStatus::Paid, 'total' => '999']);

    $this->actingAs($this->admin)->get('/invoices?tab=sale')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total.count', 3)
            ->where('stats.total.amount', 600)
            ->where('stats.draft.count', 1)
            ->where('stats.draft.amount', 100)
            ->where('stats.sent.amount', 200)
            ->where('stats.paid.amount', 300));
});

it('ships expense summary stats with counts and euro totals', function (): void {
    $approved = Expense::factory()->create(['company_id' => $this->company->id, 'total' => '150']);
    $approved->approved = true;
    $approved->save();
    Expense::factory()->create(['company_id' => $this->company->id, 'total' => '50']); // pending (approved=false)

    $this->actingAs($this->admin)->get('/expenses')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total.count', 2)
            ->where('stats.total.amount', 200)
            ->where('stats.approved.count', 1)
            ->where('stats.approved.amount', 150)
            ->where('stats.pending.count', 1)
            ->where('stats.pending.amount', 50));
});
