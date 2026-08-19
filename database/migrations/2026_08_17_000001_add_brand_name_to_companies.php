<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional short display name shown to WORKERS (home, consent, check-in,
 * payslip). Falls back to the legal `name` when blank. Additive + safe: a new
 * nullable column, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('brand_name', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('brand_name');
        });
    }
};
