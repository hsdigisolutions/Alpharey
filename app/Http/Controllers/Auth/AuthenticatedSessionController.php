<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $audit->log('login', null, null, null, null, 'auth');

        $user = $request->user();

        // Post-login routing by role (REQUIREMENTS.md Screen 01)
        return redirect()->intended(
            $user !== null && $user->isSuperAdmin() ? route('welcome') : route('dashboard'),
        );
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->log('logout', null, null, null, null, 'auth');

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
