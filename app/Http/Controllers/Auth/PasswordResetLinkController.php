<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Always responds with the same message whether or not the email
     * exists — no account enumeration.
     */
    public function store(PasswordResetLinkRequest $request): RedirectResponse
    {
        Password::sendResetLink($request->only('email'));

        return back()->with('success', __('ui.auth.reset_link_sent'));
    }
}
