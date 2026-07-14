<?php

namespace App\Services\LegacyImport\Importers;

use App\Services\LegacyImport\AbstractImporter;
use App\Services\Settings\SettingsService;

/**
 * Legacy settings → new settings (DATA_MIGRATION.md §3.11). Whitelist
 * remap only: AI configuration, API keys, and anything secret-shaped is
 * NEVER imported (secret hygiene, §3.8). Unmapped keys are skipped and
 * counted — the legacy dump stays the archive of record.
 */
class SettingsImporter extends AbstractImporter
{
    /** @var array<string, string> legacy key → new key */
    private const KEY_MAP = [
        'app_name' => 'general.app_name',
        'default_language' => 'general.default_locale',
        'timezone' => 'general.timezone',
        'session_timeout' => 'general.session_timeout_minutes',
    ];

    public function name(): string
    {
        return 'settings';
    }

    protected function import(): void
    {
        $settings = app(SettingsService::class);

        foreach ($this->legacy('settings')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->key)) {
                $this->skipped++;

                continue;
            }

            $newKey = self::KEY_MAP[$row->key] ?? null;

            if ($newKey === null) {
                $this->skipped++;

                continue;
            }

            $settings->set($newKey, $row->value);
            $this->recordMapping($row->key, $newKey);
            $this->imported++;
        }
    }
}
