<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * URL-triggered scheduler for hosts where shell cron is unavailable (MukHost).
 * An external service (cron-job.org) hits GET /cron/run?token=… every minute;
 * this runs `schedule:run`, which fires the due scheduled commands (auto-absent,
 * notification sweeps, queue worker, …).
 *
 * Security: the request must carry the exact CRON_TOKEN (constant-time compare).
 * The token is never written to the log or the response. The endpoint only ever
 * runs the internal scheduler — it exposes no data and takes no user input.
 */
class CronController extends Controller
{
    public function run(Request $request): Response
    {
        $expected = (string) config('app.cron_token', '');
        $provided = (string) $request->query('token', '');

        // Deny when unconfigured or mismatched — constant-time to avoid timing
        // attacks. Never reveal which of the two it was.
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response('Unauthorized', 403)->header('Content-Type', 'text/plain');
        }

        // Guard against overlapping runs if a minute's work ever spills past 60s
        // (cron-job.org calls every minute). A busy tick is still a success.
        $lock = Cache::lock('cron:schedule-run', 55);
        if (! $lock->get()) {
            return response('OK (busy) '.now()->toDateTimeString(), 200)->header('Content-Type', 'text/plain');
        }

        try {
            Artisan::call('schedule:run');
        } finally {
            $lock->release();
        }

        // Append-only run log (no token, no payload) so we can confirm cadence.
        Log::build(['driver' => 'single', 'path' => storage_path('logs/cron.log')])
            ->info('schedule:run via URL trigger at '.now()->toDateTimeString());

        return response('OK '.now()->toDateTimeString(), 200)->header('Content-Type', 'text/plain');
    }
}
