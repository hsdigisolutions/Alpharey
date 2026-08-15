<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

it('provides the Urdu worker dictionary with the agreed key translations', function (): void {
    expect(trans('ui.worker.check_in', [], 'ur'))->toBe('چیک ان')
        ->and(trans('ui.worker.check_out', [], 'ur'))->toBe('چیک آؤٹ')
        ->and(trans('ui.worker.report_absence', [], 'ur'))->toBe('میں آج غیر حاضر ہوں — وجہ بتائیں')
        ->and(trans('ui.worker_vehicles.take_vehicle', [], 'ur'))->toBe('گاڑی لیں')
        ->and(trans('ui.worker.powered_by', [], 'ur'))->toBe('Powered by AlphaRey');
});

it('lets a worker switch the PWA to Urdu', function (): void {
    $worker = User::factory()->create(['role' => UserRole::Worker]);

    $this->actingAs($worker)->post('/locale', ['locale' => 'ur'])->assertRedirect()->assertSessionHasNoErrors();

    expect($worker->fresh()->locale)->toBe('ur');
});

it('refuses Urdu for a CRM user — it is worker PWA only', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany(Company::factory()->create())->create();

    $this->actingAs($admin)->post('/locale', ['locale' => 'ur'])->assertSessionHasErrors('locale');

    expect($admin->fresh()->locale)->not->toBe('ur');
});
