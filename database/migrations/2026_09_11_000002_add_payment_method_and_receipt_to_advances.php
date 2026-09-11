<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue 2 — an advance can now record HOW it was paid out: bank transfer (with a
 * proof-of-transfer receipt on the private disk) or cash (the existing Reason
 * field explains it). All additive + nullable so every existing advance is
 * unaffected. receipt_path / receipt_name are server-set (never fillable), like
 * every other private-file column in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table): void {
            $table->string('payment_method', 20)->nullable()->after('payroll_month');
            $table->string('receipt_path')->nullable()->after('payment_method');
            $table->string('receipt_name')->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'receipt_path', 'receipt_name']);
        });
    }
};
