<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step verification (TOTP), mandatory for every login — SECURITY.md §1.
 *
 * Uses pragmarx/google2fa, the same engine Laravel Fortify wraps. Fortify
 * itself is not adopted because it would replace this project's auth
 * controllers, which already carry the throttle, the `active` credential check
 * and the audit hooks; the crypto is identical either way.
 *
 * Everything secret here (the shared key, the recovery codes) is stored through
 * the model's `encrypted` casts and is in $hidden, so it never reaches an audit
 * row or an Inertia payload.
 */
class TwoFactorService
{
    /** Recovery codes issued per enrolment. */
    private const RECOVERY_CODE_COUNT = 8;

    /**
     * How many 30-second windows either side of now are accepted. One window
     * tolerates ordinary clock drift between the phone and the server without
     * meaningfully widening the guessing surface.
     */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Verify a 6-digit TOTP against the user's secret.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        // Digits only — an authenticator never produces anything else, and this
        // keeps stray input away from the verifier.
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        return $this->engine->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * Spend a recovery code. Codes are SINGLE USE: a match is removed before
     * the method returns, so the same slip of paper cannot be replayed.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes;

        if (! is_array($codes) || $codes === []) {
            return false;
        }

        $candidate = strtoupper(trim($code));
        $remaining = [];
        $matched = false;

        foreach ($codes as $stored) {
            // hash_equals keeps the comparison constant-time.
            if (! $matched && hash_equals((string) $stored, $candidate)) {
                $matched = true;

                continue; // burn it
            }

            $remaining[] = $stored;
        }

        if ($matched) {
            $user->two_factor_recovery_codes = $remaining;
            $user->save();
        }

        return $matched;
    }

    /**
     * Fresh single-use recovery codes. Shown once at enrolment; after that the
     * user only ever sees them again by regenerating.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->values()
            ->all();
    }

    /**
     * Begin enrolment: a secret is stored but NOT confirmed, so it grants
     * nothing until the user proves they can read a code from it.
     */
    public function startEnrolment(User $user): string
    {
        // Reuse an existing UNCONFIRMED secret so a page refresh — or the
        // re-render after a failed confirm — shows the SAME QR. Regenerating on
        // every open rotated the secret out from under a phone that had already
        // scanned it, so every code the app produced read "invalid". An
        // unconfirmed secret grants nothing (isEnrolled requires a confirmed_at),
        // so keeping it until confirmation is safe.
        if ($user->two_factor_confirmed_at === null) {
            try {
                $existing = $user->two_factor_secret;
                if (is_string($existing) && $existing !== '') {
                    return $existing;
                }
            } catch (\Throwable) {
                // An undecryptable/legacy value → fall through and mint a fresh one.
            }
        }

        $secret = $this->generateSecret();

        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return $secret;
    }

    /**
     * Finish enrolment once the user echoes a valid code back.
     *
     * @return list<string>|null the recovery codes, or null when the code is wrong
     */
    public function confirmEnrolment(User $user, string $code): ?array
    {
        if (! $this->verifyCode($user, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes;
        $user->two_factor_confirmed_at = now();
        $user->save();

        return $codes;
    }

    /**
     * Issue a fresh set of recovery codes for an already-enrolled user, burning
     * the old set. Used by the self-service "regenerate recovery codes" action;
     * the secret and the enrolment are untouched, only the codes change.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes;
        $user->save();

        return $codes;
    }

    /**
     * Clear a user's second factor — the Super Admin's "lost the phone" lever.
     * They are forced through enrolment again on their next login.
     */
    public function reset(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
    }

    public function isEnrolled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null
            && is_string($user->two_factor_secret)
            && $user->two_factor_secret !== '';
    }

    /**
     * The otpauth:// URI an authenticator app consumes, rendered as an inline
     * SVG data URI. Inline because the CSP forbids external image hosts — the
     * QR must never be generated by a third-party service (it would leak the
     * shared secret).
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $uri = $this->engine->getQRCodeUrl(
            (string) config('app.name'),
            (string) $user->email,
            $secret,
        );

        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($uri));
    }
}
