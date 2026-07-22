<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the worker PWA: only a worker account, and only one that is actually
 * linked to an employee record, may enter.
 *
 * The employee link is checked here rather than in each controller because
 * EVERY worker screen is about "my attendance" — without an employee there is
 * no `my`, and every downstream query would be operating on nothing. An
 * unlinked worker account is a half-finished setup, so it gets a clear 403
 * instead of an empty app.
 */
class EnsureWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isWorker()) {
            abort(403);
        }

        if ($user->employee()->withoutGlobalScopes()->doesntExist()) {
            abort(403, 'This worker account is not linked to an employee record.');
        }

        return $next($request);
    }
}
