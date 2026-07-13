<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value application settings backed by the settings table, cached per key.
 * Values are JSON-encoded so arrays and scalars round-trip faithfully.
 */
class SettingsService
{
    private const CACHE_PREFIX = 'settings:';

    public function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key): string {
            $setting = Setting::query()->where('key', $key)->first();

            // Wrap in json_encode so "not set" is distinguishable from stored null.
            return json_encode(['exists' => $setting !== null, 'value' => $setting?->value], JSON_THROW_ON_ERROR);
        });

        /** @var array{exists: bool, value: string|null} $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! $decoded['exists']) {
            return $default;
        }

        return $decoded['value'] === null
            ? null
            : json_decode($decoded['value'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR)],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
