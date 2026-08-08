<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic day-type detection. On check-out the system grades the day from the
 * hours worked (full / half / hourly, thresholds configurable per company), so
 * a worker never picks the type and an admin only overrides when needed.
 *
 *   auto_day_type    — what the system detected (kept even after an override)
 *   is_auto_detected — true while the system's grade stands; an admin edit that
 *                      sets day_type flips it to false (a manual override)
 *
 * `day_type` (the FINAL, priced type) already exists; neither new column is
 * mass-assignable — both are set server-side only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->string('auto_day_type', 20)->nullable()->after('day_type');
            $table->boolean('is_auto_detected')->default(false)->after('auto_day_type');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropColumn(['auto_day_type', 'is_auto_detected']);
        });
    }
};
