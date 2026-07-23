<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence that a worker was INFORMED before the app ever captured a GPS fix
 * or a selfie.
 *
 * Spanish employment-data law (LOPDGDD art. 90 for geolocation; RD-ley 8/2019
 * for the mandatory time record) rests worker monitoring on the employment
 * relationship and the employer's legal duties — NOT on consent, which an
 * employee cannot freely give. What the employer owes instead is prior, clear
 * information (deber de información). So this is not a consent flag: it records
 * that the worker was SHOWN the privacy notice and acknowledged reading it,
 * before their first punch.
 *
 * `..._at` is when they acknowledged; `..._version` is which version of the
 * notice they saw, so that if the notice text changes materially the worker is
 * re-shown it. Both nullable: an employee without the app never acknowledges
 * anything, and a fresh worker has not yet opened it. The overwrite is itself
 * audited (Auditable), so audit_logs keeps the append-only history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->timestamp('privacy_notice_ack_at')->nullable()->after('user_id');
            $table->unsignedSmallInteger('privacy_notice_ack_version')->nullable()->after('privacy_notice_ack_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['privacy_notice_ack_at', 'privacy_notice_ack_version']);
        });
    }
};
