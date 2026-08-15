<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The document scan only fired an "expired" alert on the EXACT expiry day
 * (daysLeft === 0), and its query excluded any expiry_date before today. So a
 * document that expires today but is uploaded after the 07:00 scan — or any
 * document that quietly slipped past its date on a day the cron missed — never
 * produced an expired notification. We now alert once when a current document
 * is expired (expiry_date <= today), guarded by this timestamp so it does not
 * re-fire on every daily run. A renewed document is a NEW row (is_current), so
 * the guard re-arms naturally. Server-set, never fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->timestamp('expiry_notified_at')->nullable()->after('is_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('expiry_notified_at');
        });
    }
};
