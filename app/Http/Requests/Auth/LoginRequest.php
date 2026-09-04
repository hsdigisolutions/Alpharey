<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * Attempt authentication: rate limited (5/min per email+IP), inactive
     * accounts rejected, "remember me" honored (30 days — set on the guard
     * in AppServiceProvider).
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');

        // Workers get a LONG-LIVED (remember-me) session by default so a phone
        // left idle on site never bounces the crew back to login mid-shift. The
        // 30-day duration is set on the guard in AppServiceProvider; the remember
        // cookie silently re-authenticates past the ordinary session idle window,
        // so the only logout is an explicit worker logout or an admin
        // deactivation (EnsureUserIsActive drops a deactivated user immediately).
        $remember = $this->boolean('remember') || $this->isWorkerLogin();

        if (! Auth::guard('web')->attempt($credentials + ['active' => true], $remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('ui.auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Whether the email being authenticated belongs to a Worker — resolved by
     * role so remember-me is forced on for the crew without them ticking a box.
     * Enumeration-safe: it changes only the (invisible) remember flag, never the
     * response, and a missing email simply reads as "not a worker".
     */
    private function isWorkerLogin(): bool
    {
        // value('role') hydrates one row and applies the cast, so it returns a
        // UserRole enum (or null for an unknown email) — compare to the case.
        return User::query()->where('email', $this->string('email')->value())->value('role')
            === UserRole::Worker;
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('ui.auth.throttled', ['seconds' => $seconds]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
