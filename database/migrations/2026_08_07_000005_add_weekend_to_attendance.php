<?php

use App\Models\Attendance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voluntary weekend / optional work days. A worker who chooses to come in on a
 * Saturday or Sunday can be paid a premium (× 1.5, × 2, or a custom amount);
 * those who stay home are NOT marked absent.
 *
 *  - is_weekend        auto-detected server-side from the date (never client)
 *  - weekend_rate_type normal | x1.5 | x2 | custom
 *  - weekend_rate_amount  the flat amount for a custom weekend rate
 *
 * Existing rows are backfilled: is_weekend from the stored date's day of week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->boolean('is_weekend')->default(false)->after('day_type');
            $table->string('weekend_rate_type', 20)->nullable()->after('is_weekend');
            $table->decimal('weekend_rate_amount', 10, 2)->nullable()->after('weekend_rate_type');
        });

        // Backfill is_weekend from the date (1=Mon … 7=Sun via ISO day of week).
        Attendance::query()->withoutGlobalScopes()->get(['id', 'date'])
            ->each(function (Attendance $row): void {
                if ($row->date->isWeekend()) {
                    DB::table('attendance')->where('id', $row->id)->update(['is_weekend' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['is_weekend', 'weekend_rate_type', 'weekend_rate_amount']);
        });
    }
};
