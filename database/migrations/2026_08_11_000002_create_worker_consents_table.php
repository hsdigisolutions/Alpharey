<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker privacy consent — legal evidence (GDPR art. 7 & 13, LOPDGDD 3/2018,
 * RD-ley 8/2019). APPEND-ONLY: every consent decision (first acceptance, a
 * re-acceptance after a version bump, a change of GPS/selfie preference, a
 * revocation) is a NEW row capturing the full snapshot + the evidence context
 * (IP, user-agent, exact timestamp/timezone, the exact text shown, language).
 *
 * Legal model:
 *  - `consent_attendance` — the mandatory time record (RD-ley 8/2019) is a legal
 *    obligation, NOT refusable; this checkbox is the worker's acknowledgement
 *    that they were informed (deber de información, GDPR art. 13).
 *  - `consent_gps` / `consent_photo` — genuinely OPTIONAL extras the app works
 *    without, so consent IS a valid basis for them (freely given, no detriment).
 *
 * The "active" consent for a worker is the latest row with the CURRENT version
 * and `revoked_at` NULL. Revoking or an admin reset sets `revoked_at` on the
 * superseded row, so the history is never destroyed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('consent_version', 40);            // e.g. v1.0-2026-08
            $table->string('ip_address', 45)->nullable();     // IPv4/IPv6
            $table->text('user_agent')->nullable();           // browser + device
            $table->timestamp('consented_at');                // exact instant
            $table->string('timezone', 64)->default('Europe/Madrid');

            $table->boolean('consent_attendance')->default(false); // mandatory ack
            $table->boolean('consent_gps')->default(false);        // optional
            $table->boolean('consent_photo')->default(false);      // optional

            $table->longText('consent_text_shown');           // exact text (evidence)
            $table->string('language', 2)->default('es');     // es | en

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_consents');
    }
};
