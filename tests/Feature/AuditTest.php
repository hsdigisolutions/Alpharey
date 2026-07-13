<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Tests\Fixtures\ScopedItem;

beforeEach(function (): void {
    createScopedItemsTable();

    $this->company = Company::factory()->create();
    $this->user = User::factory()->forCompany($this->company)->create();
    $this->actingAs($this->user);
});

it('logs model creation with the acting user and new values', function (): void {
    $item = ScopedItem::query()->create(['name' => 'Grúa Torre', 'secret' => 'oculto']);

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect($log->action)->toBe('created')
        ->and($log->model_type)->toBe(ScopedItem::class)
        ->and($log->model_id)->toBe((string) $item->id)
        ->and($log->module)->toBe('employees')
        ->and($log->entity_name)->toBe('Grúa Torre')
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->user_name)->toBe($this->user->name)
        ->and($log->company_id)->toBe($this->company->id)
        ->and($log->new_values)->toHaveKey('name', 'Grúa Torre');
});

it('never captures hidden attributes in the audit trail', function (): void {
    ScopedItem::query()->create(['name' => 'x', 'secret' => 'nunca-visible']);

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect($log->new_values)->not->toHaveKey('secret')
        ->and(json_encode($log->new_values))->not->toContain('nunca-visible');
});

it('logs updates with old and new values for changed keys only', function (): void {
    $item = ScopedItem::query()->create(['name' => 'antes']);

    $item->update(['name' => 'después']);

    $log = AuditLog::query()->where('action', 'updated')->latest('id')->firstOrFail();

    expect($log->old_values)->toBe(['name' => 'antes'])
        ->and($log->new_values)->toBe(['name' => 'después']);
});

it('writes no update log when nothing changed', function (): void {
    $item = ScopedItem::query()->create(['name' => 'igual']);

    $item->update(['name' => 'igual']);

    expect(AuditLog::query()->where('action', 'updated')->count())->toBe(0);
});

it('logs deletion with the final attribute snapshot', function (): void {
    $item = ScopedItem::query()->create(['name' => 'borrado']);
    $item->delete();

    $log = AuditLog::query()->where('action', 'deleted')->latest('id')->firstOrFail();

    expect($log->old_values)->toHaveKey('name', 'borrado')
        ->and($log->new_values)->toBeNull();
});

it('captures request context on audit rows', function (): void {
    ScopedItem::query()->create(['name' => 'ctx']);

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect($log->ip_address)->not->toBeNull()
        ->and($log->request_method)->not->toBeNull();
});

it('refuses to update audit logs', function (): void {
    ScopedItem::query()->create(['name' => 'sellado']);

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect(fn () => $log->update(['description' => 'tampered']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses to delete audit logs', function (): void {
    ScopedItem::query()->create(['name' => 'sellado']);

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect(fn () => $log->delete())->toThrow(RuntimeException::class, 'append-only');
});
