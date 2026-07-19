<?php

use App\Http\Middleware\ApplySessionTimeout;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            ApplySessionTimeout::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureAdmin::class,
            'super_admin' => EnsureSuperAdmin::class,
            'two_factor' => RequireTwoFactor::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render 403/404/500/503 through the bilingual Inertia error page in
        // production; keep Laravel's debug pages during local development.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (! app()->environment(['local', 'testing'])
                && in_array($status, [403, 404, 500, 503], true)) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            if ($status === 419) {
                return back()->with([
                    'error' => __('ui.errors.419_message'),
                ]);
            }

            return $response;
        });
    })->create();
