<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

function makeLog(array $attributes = []): void
{
    AuditLog::query()->create(array_merge([
        'action' => 'created',
        'module' => 'employees',
        'user_name' => 'Test User',
        'created_at' => now(),
    ], $attributes));
}

it('is visible to admins only', function (): void {
    $this->actingAs($this->admin)->get('/admin/audit-logs')->assertOk();

    $user = User::factory()->forCompany($this->companyA)->create();
    $this->actingAs($user)->get('/admin/audit-logs')->assertForbidden();
});

it('locks company admins to their own company trail', function (): void {
    makeLog(['company_id' => $this->companyA->id, 'entity_name' => 'own-row']);
    makeLog(['company_id' => $this->companyB->id, 'entity_name' => 'foreign-row']);

    $this->actingAs($this->admin)->get('/admin/audit-logs')
        ->assertInertia(function (Assert $page): void {
            $entities = collect($page->toArray()['props']['logs']['data'])->pluck('entity_name');

            expect($entities)->toContain('own-row')
                ->not->toContain('foreign-row');
        });
});

it('shows the super admin everything while browsing all companies', function (): void {
    makeLog(['company_id' => $this->companyA->id, 'entity_name' => 'row-a']);
    makeLog(['company_id' => $this->companyB->id, 'entity_name' => 'row-b']);

    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->get('/admin/audit-logs')
        ->assertInertia(function (Assert $page): void {
            $entities = collect($page->toArray()['props']['logs']['data'])->pluck('entity_name');

            expect($entities)->toContain('row-a')->toContain('row-b');
        });
});

it('filters by action', function (): void {
    makeLog(['company_id' => $this->companyA->id, 'action' => 'created', 'entity_name' => 'c-row']);
    makeLog(['company_id' => $this->companyA->id, 'action' => 'deleted', 'entity_name' => 'd-row']);

    $this->actingAs($this->admin)->get('/admin/audit-logs?action=deleted')
        ->assertInertia(function (Assert $page): void {
            $rows = collect($page->toArray()['props']['logs']['data']);

            expect($rows->pluck('action')->unique()->all())->toBe(['deleted']);
        });
});

it('exports the filtered trail as CSV and audits the export', function (): void {
    makeLog(['company_id' => $this->companyA->id, 'entity_name' => 'export-me']);

    $response = $this->actingAs($this->admin)->get('/admin/audit-logs/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and(AuditLog::query()->where('action', 'exported')->where('module', 'audit_logs')->exists())
        ->toBeTrue();
});

it('has no update or delete routes for audit logs', function (): void {
    makeLog(['company_id' => $this->companyA->id]);
    $log = AuditLog::query()->firstOrFail();

    // No matching route exists for any verb on a single audit log
    $this->actingAs($this->admin)->put("/admin/audit-logs/{$log->id}", [])->assertNotFound();
    $this->actingAs($this->admin)->delete("/admin/audit-logs/{$log->id}")->assertNotFound();
});

it('records manual audit entries with module tags', function (): void {
    $this->actingAs($this->admin);

    app(AuditLogger::class)->log('viewed', null, null, null, 'Detalle de empleado', 'employees');

    $log = AuditLog::query()->where('action', 'viewed')->firstOrFail();

    expect($log->module)->toBe('employees')
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->company_id)->toBe($this->companyA->id);
});
