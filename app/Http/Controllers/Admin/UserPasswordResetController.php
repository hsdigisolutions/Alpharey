<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * The Super Admin's side of the "request a password reset" flow (client
 * decision 2026-07-23): a user cannot set their own password, so they raise a
 * request from My Account and a Super Admin sends them a reset link from the
 * Permission Matrix — the same lost-phone shape as the 2FA reset lever.
 *
 * The Super Admin never sees or sets the password: this fires the standard,
 * bilingual reset-link email, and the user chooses their own new password.
 */
class UserPasswordResetController extends Controller
{
    public function send(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();

        // Super Admin only — enforced here, not merely by hiding the button.
        abort_unless($actor !== null && $actor->isSuperAdmin(), 403);

        Password::sendResetLink(['email' => $user->email]);

        // Clear the pending flag: the request has been actioned.
        $user->forceFill(['password_reset_requested_at' => null])->save();

        $audit->log('password_reset_sent', $user, null, null, $user->name, 'users');

        return back()->with('success', __('ui.permissions.password_reset_sent'));
    }
}
