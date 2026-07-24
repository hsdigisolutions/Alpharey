<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin surfaces shared by Super Admin and Company Admin: permission
 * matrix, audit logs, settings (REQUIREMENTS.md screens 17/25/26).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || (! $user->isSuperAdmin() && ! $user->isAdmin())) {
            abort(403);
        }

        return $next($request);
    }
}
