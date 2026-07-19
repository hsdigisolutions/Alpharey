<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mandatory two-step verification (SECURITY.md §1 — the "2FA-ready"
     * architecture, now switched on).
     *
     * Both the TOTP secret and the recovery codes are secrets: they get
     * `encrypted` casts on the model and sit in $hidden, exactly like NIF and
     * pay data, so they can never reach an audit row or an Inertia payload.
     *
     * `two_factor_confirmed_at` is what makes enrolment real: a secret alone is
     * only a pending setup. A user counts as enrolled once they have proved
     * they can produce a code from it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
