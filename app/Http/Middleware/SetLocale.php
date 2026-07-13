<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        $locale = $user instanceof User
            ? $user->locale
            : $request->session()->get('locale', config('app.locale'));

        if (in_array($locale, ['es', 'en'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
