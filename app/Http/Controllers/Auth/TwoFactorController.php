<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two-step verification — mandatory on every login (SECURITY.md §1).
 *
 * Two distinct states, deliberately handled differently:
 *
 *  - ENROLLED: the password alone must not grant a session, so login parks the
 *    user (id in the session, guard logged out) and only completes once a TOTP
 *    or recovery code checks out.
 *  - NOT YET ENROLLED: the user IS logged in, but RequireTwoFactor bounces
 *    every request here until they confirm a secret, so the setup screen can
 *    use the normal authenticated context.
 */
class TwoFactorController extends Controller
{
    /** Attempts allowed per minute against the challenge. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /** Enrolment screen — QR + manual key. */
    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($this->twoFactor->isEnrolled($user)) {
            return redirect()->route('dashboard');
        }

        // A fresh secret each time the screen is opened until it is confirmed,
        // so an abandoned half-enrolment never lingers as a usable key.
        $secret = $this->twoFactor->startEnrolment($user);

        return Inertia::render('Auth/TwoFactorSetup', [
            'qr' => $this->twoFactor->qrCodeSvg($user, $secret),
            // Shown so a user whose camera will not scan can type it in.
            'secret' => $secret,
        ]);
    }

    /** Confirm enrolment by echoing back a code from the app. */
    public function confirm(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);

        $codes = $this->twoFactor->confirmEnrolment($user, $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('ui.two_factor.invalid_code')]);
        }

        $audit->log('two_factor_enabled', null, null, null, null, 'auth');

        // The one and only time the recovery codes are shown.
        return redirect()->route('two-factor.recovery')->with('recovery_codes', $codes);
    }

    /** One-time display of the freshly minted recovery codes. */
    public function recovery(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->get('recovery_codes');

        if (! is_array($codes)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/TwoFactorRecovery', ['codes' => $codes]);
    }

    /** Challenge screen shown between password and session. */
    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('two_factor.pending_id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Verify the challenge and, only then, actually log the user in.
     */
    public function verify(Request $request, AuditLogger $audit): RedirectResponse
    {
        $pendingId = $request->session()->get('two_factor.pending_id');

        if (! is_int($pendingId) && ! is_string($pendingId)) {
            return redirect()->route('login');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);

        $this->ensureNotRateLimited($pendingId);

        $user = User::query()->find($pendingId);

        // An account disabled between the two steps must not slip through.
        if ($user === null || ! $user->active) {
            $request->session()->forget(['two_factor.pending_id', 'two_factor.remember']);

            throw ValidationException::withMessages(['code' => __('ui.auth.account_disabled')]);
        }

        $code = $validated['code'];
        $viaRecovery = false;

        if (! $this->twoFactor->verifyCode($user, $code)) {
            // Fall back to a recovery code — single use, burned on success.
            if (! $this->twoFactor->consumeRecoveryCode($user, $code)) {
                RateLimiter::hit($this->throttleKey($pendingId));

                throw ValidationException::withMessages(['code' => __('ui.two_factor.invalid_code')]);
            }

            $viaRecovery = true;
        }

        RateLimiter::clear($this->throttleKey($pendingId));

        $remember = (bool) $request->session()->get('two_factor.remember', false);

        $request->session()->forget(['two_factor.pending_id', 'two_factor.remember']);
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $audit->log($viaRecovery ? 'two_factor_recovery_used' : 'login', null, null, null, null, 'auth');

        return redirect()->intended(
            $user->isSuperAdmin() ? route('welcome') : route('dashboard'),
        );
    }

    /**
     * Super Admin clears someone's second factor — the "lost the phone" lever.
     * The target is forced through enrolment again on their next login.
     */
    public function reset(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($actor !== null && $actor->isSuperAdmin(), 403);

        $this->twoFactor->reset($user);

        $audit->log('two_factor_reset', $user, null, null, $user->name, 'auth');

        return back()->with('success', __('ui.two_factor.reset_done'));
    }

    private function throttleKey(int|string $pendingId): string
    {
        return 'two-factor:'.$pendingId;
    }

    /**
     * @throws ValidationException
     */
    private function ensureNotRateLimited(int|string $pendingId): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($pendingId), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => __('ui.auth.throttled', [
                'seconds' => RateLimiter::availableIn($this->throttleKey($pendingId)),
            ]),
        ]);
    }
}
