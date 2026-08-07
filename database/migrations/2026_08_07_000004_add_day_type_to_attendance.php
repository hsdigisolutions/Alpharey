<?php

use App\Models\Attendance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Day type (dehadi) support. A daily worker is paid by the jornada, not the
 * hour, so a day can be a full day, a half day, hourly, or per-meter piecework.
 *
 *   full      total = daily rate x 1.0
 *   half      total = daily rate x 0.5
 *   hourly    total = hours x hourly rate
 *   per_meter total = quantity x per-meter rate
 *
 * `quantity` carries the metres for a per-meter day. Existing rows are
 * backfilled: an hourly-snapshot row is `hourly`, everything else `full`
 * (the dehadi default), preserving each row's frozen total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->string('day_type', 20)->nullable()->after('mode');
            $table->decimal('quantity', 10, 2)->nullable()->after('hours_worked');
        });

        // Backfill: hourly snapshot -> hourly; otherwise a full day.
        Attendance::query()->withoutGlobalScopes()
            ->where('wage_type_snapshot', 'hourly')
            ->update(['day_type' => 'hourly']);

        Attendance::query()->withoutGlobalScopes()
            ->whereNull('day_type')
            ->update(['day_type' => 'full']);

        // A legacy full day never stored a daily rate in wage_rate_snapshot
        // (it held the hourly column, often null). Seed it from the frozen
        // total so editing the row later recomputes to the same amount.
        DB::table('attendance')
            ->where('day_type', 'full')
            ->where(function ($q): void {
                $q->whereNull('wage_rate_snapshot')->orWhere('wage_rate_snapshot', 0);
            })
            ->update(['wage_rate_snapshot' => DB::raw('total_amount')]);

        Schema::table('payrolls', function (Blueprint $table) {
            $table->text('day_type_summary')->nullable()->after('rate_periods'); // encrypted JSON
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['day_type', 'quantity']);
        });
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('day_type_summary');
        });
    }
};
