<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the authenticated user's saved language preference (users.locale).
 * Guests get the session-stored choice from the login-screen toggle, falling
 * back to the app default (es). Labels always render bilingually regardless —
 * the locale only decides which language is primary.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $default = rescue(
            fn () => app(SettingsService::class)->get('general.default_locale'),
            null,
            false,
        );

        $locale = $user instanceof User
            ? $user->locale
            : $request->session()->get('locale', is_string($default) ? $default : config('app.locale'));

        // 'ur' (Urdu) is a worker-PWA-only third language; es/en drive the CRM.
        if (in_array($locale, ['es', 'en', 'ur'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
