<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Measurements get a proper three-state review workflow (2026-08-13):
 * pending → approved → rejected, with a rejection reason the supervisor must
 * give. The legacy `approved` boolean stays (kept in sync = status===approved)
 * so every existing per-meter P&L / invoice reader keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table): void {
            $table->string('status', 20)->default('pending')->after('approved');
            $table->text('rejection_reason')->nullable()->after('status');
        });

        // Back-fill from the existing boolean: approved rows → approved, the
        // rest → pending (there was no reject state before).
        DB::table('measurements')->where('approved', true)->update(['status' => 'approved']);
        DB::table('measurements')->where('approved', false)->update(['status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table): void {
            $table->dropColumn(['status', 'rejection_reason']);
        });
    }
};
