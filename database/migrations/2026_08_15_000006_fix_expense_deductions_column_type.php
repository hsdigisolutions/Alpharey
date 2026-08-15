<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payrolls.expense_deductions` is cast `encrypted` on the model (it is
 * per-employee pay data, like every other payroll money field), but the
 * migration that added it (2026_08_12_000001) created it as decimal(14,2) —
 * unlike its siblings, which are all `text`. On MySQL a decimal column cannot
 * hold an encrypted payload, so reading it back through the cast throws
 * DecryptException and payroll calculation 500s. SQLite is type-loose, which is
 * why the test suite never caught it. Widen it to text, matching the siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->text('expense_deductions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->decimal('expense_deductions', 14, 2)->default(0)->change();
        });
    }
};
