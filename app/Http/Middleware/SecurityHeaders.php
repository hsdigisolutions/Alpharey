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
        // geolocation=(self) — the Worker PWA captures a GPS fix on check-in
        // and check-out. It was an EMPTY allow-list, which forbids the API to
        // every origin including our own, so navigator.geolocation would have
        // failed on every page. Camera is likewise self-only (the check-in
        // selfie); microphone stays fully denied — nothing here records audio.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');

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
