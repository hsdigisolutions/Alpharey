<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vehicle fine must NEVER auto-deduct from a worker's salary. The admin
 * decides per fine, explicitly, whether it comes off pay and for which month.
 * These two columns record that decision; payroll deducts only when the flag
 * is set. Both are server-set (a controller action), never mass-assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_fines', function (Blueprint $table): void {
            $table->boolean('deduct_from_salary')->default(false)->after('charged_to');
            $table->string('deduction_month', 7)->nullable()->after('deduct_from_salary'); // Y-m
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_fines', function (Blueprint $table): void {
            $table->dropColumn(['deduct_from_salary', 'deduction_month']);
        });
    }
};
