<?php

namespace App\Support;

use App\Services\Settings\SettingsService;

/**
 * The worker geolocation + selfie privacy notice — the single authority for the
 * CURRENT consent version and for the exact notice text the worker is shown.
 *
 * Legal model (see docs/GDPR_WORKER_NOTICE.md):
 *  - The attendance TIME RECORD is a legal obligation (RD-ley 8/2019), not
 *    refusable — the mandatory checkbox is an ACKNOWLEDGEMENT of the information
 *    duty (GDPR art. 13), not consent that can be declined.
 *  - GPS location and the selfie are genuinely OPTIONAL (the app works without
 *    them), so each is a separate, freely-given CONSENT (GDPR art. 7) the worker
 *    can grant or withhold, and revoke later, with no detriment.
 *
 * The version is a Setting so a Company/Super Admin can bump it (Settings →
 * Legal) to force every worker to re-accept; the exact text shown is captured
 * server-side per acceptance, so the evidence is self-contained even if the
 * lang files later change.
 */
final class WorkerPrivacyNotice
{
    public const DEFAULT_VERSION = 'v1.0-2026-08';

    /** The version currently in force (admin-managed). */
    public static function currentVersion(): string
    {
        $v = app(SettingsService::class)->get('legal.consent_version', self::DEFAULT_VERSION);

        return is_string($v) && $v !== '' ? $v : self::DEFAULT_VERSION;
    }

    /**
     * The exact, full notice text for one language — the authoritative snapshot
     * stored as evidence on each consent row. Assembled from the versioned lang
     * dictionary so it always matches what the screen renders.
     */
    public static function canonicalText(string $language): string
    {
        $lang = $language === 'en' ? 'en' : 'es';
        $t = fn (string $key): string => (string) trans("ui.worker.privacy.{$key}", [], $lang);

        $lines = [
            $t('title'),
            $t('subtitle'),
            '',
            $t('intro'),
            '',
            $t('data_title'),
            '- '.$t('data_location'),
            '- '.$t('data_selfie'),
            '- '.$t('data_time'),
            '',
            $t('why_title'), $t('why_body'),
            $t('who_title'), $t('who_body'),
            $t('retention_title'), $t('retention_body'),
            $t('rights_title'), $t('rights_body'),
            '',
            $t('gps_note'),
            '',
            $t('consent_attendance'),
            $t('consent_gps'),
            $t('consent_photo'),
        ];

        return implode("\n", $lines);
    }
}
