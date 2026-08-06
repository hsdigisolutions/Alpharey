<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers (SECURITY.md §7). CSP is enforced outside local
 * development only, because the Vite dev server serves assets from its own
 * origin during `npm run dev`.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // camera=(self) — check-in selfie. geolocation=(self) — GPS punch.
        // microphone=(self) — worker voice note at check-out. All three are
        // restricted to our own origin; the user still has to grant the browser
        // permission prompt — (self) only allows the page to ask, not auto-grant.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self)');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! app()->environment('local')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                ."img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; "
                ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
            );
        }

        return $response;
    }
}
