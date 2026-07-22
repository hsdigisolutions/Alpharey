<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link the system never had: which LOGIN belongs to which WORKER.
 *
 * `users` (authentication) and `employees` (workforce, attendance, payroll)
 * were completely unconnected — the legacy leave importer had to reconstruct
 * the relationship by NAME, which DATA_MIGRATION.md §3.7 flags as the riskiest
 * mapping in the whole migration.
 *
 * The Worker PWA makes the link mandatory: a worker signs in as a User, and
 * every check-in has to land on THEIR Employee row.
 *
 * Nullable because the overwhelming majority of employees never get a login
 * (office staff use the CRM; only site workers need the app), and unique
 * because one login is exactly one worker — sharing an account would put two
 * people's attendance on one payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('company_id')
                ->constrained()->nullOnDelete();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
