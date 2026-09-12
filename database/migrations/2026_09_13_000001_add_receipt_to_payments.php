<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 6 (2026-09-13) — proof-of-payment receipt on an invoice payment record.
 *
 * A bank-transfer payment can carry an uploaded receipt (proof of transfer);
 * cash keeps the existing free-text reference. Mirrors the Advance receipt shape
 * (Issue 2). receipt_path / receipt_name are server-set (never fillable).
 * Additive + nullable — every existing payment is unaffected, and the invoice
 * paid/unpaid derivation is untouched (a receipt is documentation only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('receipt_path')->nullable()->after('reference');
            $table->string('receipt_name')->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['receipt_path', 'receipt_name']);
        });
    }
};
