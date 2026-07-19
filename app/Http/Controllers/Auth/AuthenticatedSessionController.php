<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
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

    public function store(LoginRequest $request, AuditLogger $audit, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();

        // Two-step verification is mandatory (SECURITY.md §1). For an enrolled
        // user the password alone must NOT leave a usable session, so park them:
        // drop the guard, remember only who is pending, and let the challenge
        // complete the login. A user who has not enrolled yet stays logged in —
        // RequireTwoFactor confines them to the setup screen.
        if ($twoFactor->isEnrolled($user)) {
            $remember = $request->boolean('remember');

            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->put('two_factor.pending_id', $user->id);
            $request->session()->put('two_factor.remember', $remember);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();

        $audit->log('login', null, null, null, null, 'auth');

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
