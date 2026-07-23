<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user cannot set their own password from the My Account page (client
 * decision): instead they RAISE A REQUEST, and a Super Admin actions it — the
 * same shape as the lost-phone 2FA reset lever.
 *
 * This column is the pending flag: set when the user asks, cleared when a Super
 * Admin sends them a reset link. Nullable — the overwhelming default is "no
 * request outstanding".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_reset_requested_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_reset_requested_at');
        });
    }
};
