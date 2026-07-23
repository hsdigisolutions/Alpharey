<?php

namespace App\Http\Controllers\Account;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmPasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Account" — the self-service page every CRM user reaches from the header
 * user menu. Scope is deliberately narrow (client decisions 2026-07-23):
 *
 *  - the user edits only their own display name; email/role/company stay with
 *    the admins (email is a login id, role/company are authorization);
 *  - the user cannot set their own password — they RAISE A REQUEST and a Super
 *    Admin sends a reset link (same shape as the lost-phone 2FA lever);
 *  - the user CAN manage their own second factor: reconfigure it, or regenerate
 *    recovery codes, each behind a password re-confirmation.
 *
 * Workers never reach this — they have no CRM session (DenyWorkers) and their
 * app carries none of these controls.
 */
class AccountController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Index', [
            'account' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'role' => $user?->role->value,
                'company' => $user?->company?->name,
                'two_factor_enabled' => $user !== null && $this->twoFactor->isEnrolled($user),
                // When set, the user has a password reset waiting on a Super Admin.
                'password_reset_requested' => $user?->password_reset_requested_at !== null,
            ],
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user?->update(['name' => $request->validated('name')]);

        return back()->with('success', __('ui.account.profile_saved'));
    }

    /**
     * Raise a password-reset request for a Super Admin to action. Idempotent —
     * asking twice does not re-notify or move the timestamp while one is open.
     */
    public function requestPasswordReset(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || $user->password_reset_requested_at !== null) {
            return back()->with('success', __('ui.account.password_request_pending'));
        }

        $user->forceFill(['password_reset_requested_at' => now()])->save();

        // Notify every active Super Admin — the bell + an email. Users are not
        // company-scoped, so this reaches all of them across the group.
        $superAdmins = User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('active', true)
            ->get();

        Notification::send($superAdmins, new SystemNotification([
            'type' => 'password_reset_requested',
            'title_es' => 'Solicitud de restablecimiento de contraseña',
            'title_en' => 'Password reset requested',
            'entity' => $user->name.' · '.$user->email,
            'company' => $user->company?->name,
            'url' => '/admin/permissions?user='.$user->id,
        ]));

        app(AuditLogger::class)->log('password_reset_requested', $user, null, null, $user->name, 'users');

        return back()->with('success', __('ui.account.password_request_sent'));
    }

    /**
     * Reconfigure the second factor: after a password re-check, clear the
     * current secret and drop the user into the normal enrolment flow (they are
     * momentarily un-enrolled, so RequireTwoFactor sends them to setup and holds
     * them there until they finish — the safe state, never no second factor).
     */
    public function reconfigureTwoFactor(ConfirmPasswordRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->twoFactor->reset($user);
            $audit->log('two_factor_reconfigure_started', null, null, null, null, 'auth');
        }

        return redirect()->route('two-factor.setup');
    }

    /**
     * Fresh recovery codes for an enrolled user, after a password re-check. The
     * codes are shown once through the existing recovery screen.
     */
    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $this->twoFactor->isEnrolled($user)) {
            return back();
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $audit->log('two_factor_recovery_regenerated', null, null, null, null, 'auth');

        return redirect()->route('two-factor.recovery')->with('recovery_codes', $codes);
    }
}
