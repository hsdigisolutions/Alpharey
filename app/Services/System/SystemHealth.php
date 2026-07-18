<?php

namespace App\Services\System;

use App\Services\Settings\MailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Screen 26 — the Settings system-health panel (Phase 8). Cheap liveness
 * checks a Super Admin can glance at: database, the private storage disk, the
 * database-backed queue, and whether SMTP is configured.
 *
 * Each check returns a status ('ok' | 'warn' | 'danger') and a short detail —
 * never throws, so one failing check cannot take the Settings page down with
 * it (the whole point of a health panel is to work when things don't).
 */
class SystemHealth
{
    public function __construct(private readonly MailSettings $mail) {}

    /**
     * @return array<string, array{status: string, detail: string}>
     */
    public function check(): array
    {
        return [
            'database' => $this->database(),
            'storage' => $this->storage(),
            'queue' => $this->queue(),
            'mail' => $this->mailConfigured(),
        ];
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok', 'detail' => (string) DB::connection()->getDatabaseName()];
        } catch (Throwable $e) {
            return ['status' => 'danger', 'detail' => 'unreachable'];
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function storage(): array
    {
        try {
            $disk = Storage::disk('local');
            $probe = 'health/'.uniqid('check_', true).'.txt';
            $disk->put($probe, 'ok');
            $readable = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            return $readable
                ? ['status' => 'ok', 'detail' => 'writable']
                : ['status' => 'danger', 'detail' => 'not readable'];
        } catch (Throwable $e) {
            return ['status' => 'danger', 'detail' => 'not writable'];
        }
    }

    /**
     * The queue runs on the database driver (no daemon on cPanel) — report the
     * pending job backlog. A large backlog is a warning, not a failure: it may
     * just mean the scheduler has not run yet this minute.
     *
     * @return array{status: string, detail: string}
     */
    private function queue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            if ($failed > 0) {
                return ['status' => 'warn', 'detail' => "{$pending} pending · {$failed} failed"];
            }

            return [
                'status' => $pending > 100 ? 'warn' : 'ok',
                'detail' => "{$pending} pending",
            ];
        } catch (Throwable $e) {
            return ['status' => 'warn', 'detail' => 'unknown'];
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function mailConfigured(): array
    {
        return $this->mail->isConfigured()
            ? ['status' => 'ok', 'detail' => 'configured']
            : ['status' => 'warn', 'detail' => 'not configured'];
    }
}
