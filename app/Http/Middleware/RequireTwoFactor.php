<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step verification is mandatory for everyone (SECURITY.md §1), so a user
 * who has not enrolled yet is confined to the setup screen: every other route
 * bounces back there until they confirm a secret.
 *
 * The allow-list is deliberately tiny — the enrolment routes themselves, plus
 * logout and the locale toggle, so a user can always get back out or read the
 * screen in their own language.
 */
class RequireTwoFactor
{
    /** Routes reachable while enrolment is still outstanding. */
    private const ALLOWED = [
        'two-factor.setup',
        'two-factor.confirm',
        'two-factor.recovery',
        'logout',
        'locale.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (app(TwoFactorService::class)->isEnrolled($user)) {
            return $next($request);
        }

        foreach (self::ALLOWED as $name) {
            if ($request->routeIs($name)) {
                return $next($request);
            }
        }

        // Inertia follows a 409 + location header for a full redirect; a plain
        // redirect would be swallowed as a partial response.
        return redirect()->route('two-factor.setup');
    }
}
