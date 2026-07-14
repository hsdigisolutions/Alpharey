<?php

namespace App\Http\Middleware;

use App\Services\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the admin-configured session timeout (Settings → General,
 * default 120 min per DECISIONS.md). Prepended to the web group so it
 * runs before StartSession.
 */
class ApplySessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $minutes = rescue(
            fn () => app(SettingsService::class)->get('general.session_timeout_minutes'),
            null,
            false,
        );

        if (is_numeric($minutes) && (int) $minutes >= 5) {
            config(['session.lifetime' => (int) $minutes]);
        }

        return $next($request);
    }
}
