<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The other half of the wall: a worker account must never reach a CRM screen.
 *
 * ModulePermissions already refuses the role every module ability, so a worker
 * would be stopped at each controller's Gate check anyway. This middleware is
 * the belt to that braces — it means a screen that ever forgets its gate still
 * cannot be opened by a worker, and it sends them somewhere useful (their own
 * app) instead of a bare 403.
 */
class DenyWorkers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isWorker()) {
            return redirect()->route('worker.home');
        }

        return $next($request);
    }
}
