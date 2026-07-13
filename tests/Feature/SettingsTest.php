<?php

use App\Models\Setting;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->settings = app(SettingsService::class);
});

it('round-trips scalar values', function (): void {
    $this->settings->set('session_timeout_minutes', 120);

    expect($this->settings->get('session_timeout_minutes'))->toBe(120);
});

it('round-trips array values', function (): void {
    $this->settings->set('document_alert_days', [90, 60, 30]);

    expect($this->settings->get('document_alert_days'))->toBe([90, 60, 30]);
});

it('returns the default when a key is missing', function (): void {
    expect($this->settings->get('missing_key', 'fallback'))->toBe('fallback');
});

it('distinguishes stored null from missing', function (): void {
    $this->settings->set('nullable_key', null);

    expect($this->settings->get('nullable_key', 'fallback'))->toBeNull();
});

it('applies new values immediately after set', function (): void {
    $this->settings->set('app_name', 'Verto5');
    expect($this->settings->get('app_name'))->toBe('Verto5');

    $this->settings->set('app_name', 'Verto5 CRM');
    expect($this->settings->get('app_name'))->toBe('Verto5 CRM');
});

it('survives a cache flush by rereading the database', function (): void {
    $this->settings->set('persistent', 'value');
    Cache::flush();

    expect($this->settings->get('persistent'))->toBe('value');
});

it('forgets keys from store and cache', function (): void {
    $this->settings->set('temp', 'x');
    $this->settings->forget('temp');

    expect($this->settings->get('temp', 'gone'))->toBe('gone')
        ->and(Setting::query()->where('key', 'temp')->exists())->toBeFalse();
});
