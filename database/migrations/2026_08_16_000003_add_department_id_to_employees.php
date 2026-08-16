<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an employee to a department in the new catalogue. Nullable FK (an
 * employee may have no department); nullOnDelete so removing a department never
 * deletes employees. The legacy free-text `department` column is kept as the
 * display value and is stamped from the chosen department's name on save.
 *
 * Additive + safe: a new nullable column, no data change (department_id starts
 * null for every existing row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('department')
                ->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
