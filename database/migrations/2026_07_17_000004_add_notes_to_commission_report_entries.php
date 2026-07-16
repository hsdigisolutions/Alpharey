<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQUIREMENTS.md Screen 19 lists a Notes column on the commission table
     * that the Phase 6 schema slice missed. Added as its own migration rather
     * than by editing the original — the earlier table already carries data.
     */
    public function up(): void
    {
        Schema::table('commission_report_entries', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('adjustment_reason');
        });
    }

    public function down(): void
    {
        Schema::table('commission_report_entries', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
