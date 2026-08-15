<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle fuel → company expense automation.
 *
 * When an admin approves a worker's fuel expense we auto-create a company
 * Expense so the cost lands in the expenses module. worker_expenses gains:
 *   - vehicle_id: the vehicle the fuel was for (set from the session at log
 *     time) so the auto-expense description can name it.
 *   - auto_expense_id: the created Expense, which also GUARDS against a second
 *     create (idempotent — if set, we skip).
 * expenses gains source/source_id so the created row is recognisable as
 * auto-generated (badge) and traceable back to its worker expense. All four
 * columns are server-set, never mass-assignable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_expenses', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->foreignId('auto_expense_id')->nullable()->after('payroll_id')->constrained('expenses')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('source', 30)->nullable()->after('notes');
            $table->unsignedBigInteger('source_id')->nullable()->after('source');
            $table->index(['company_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('worker_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('auto_expense_id');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'source']);
            $table->dropColumn(['source', 'source_id']);
        });
    }
};
