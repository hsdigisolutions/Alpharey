<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Wage History with Automatic Rate Switching.
 *
 * The table has existed since Phase 2 but was effectively dormant: it stored
 * an open-ended `is_default` flag with `effective_from` only, and nothing read
 * it. This turns it into a proper effective-dated history: every rate owns a
 * closed [effective_from, effective_to] range, with exactly ONE open record
 * (effective_to = null) per employee — the currently active rate.
 *
 * The single-open invariant is enforced in WageRateService, not by a DB
 * constraint: a partial unique index (unique WHERE effective_to IS NULL) is
 * not portable across MySQL and the SQLite used in tests, and MySQL treats
 * multiple NULLs as distinct in a plain unique index anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_wage_rates', function (Blueprint $table) {
            // null = currently active (open range). Set when the next rate opens.
            $table->date('effective_to')->nullable()->after('effective_from');
            // Why the rate changed (optional) — distinct from the legacy `notes`.
            $table->text('reason')->nullable()->after('is_default');
            $table->foreignId('created_by')->nullable()->after('reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_wage_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['effective_to', 'reason']);
        });
    }
};
