<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup flag for the overdue-invoice notification: an invoice is alerted ONCE
 * when it first crosses its due date, never re-spammed every daily sweep.
 * Server-set (not fillable); cleared back to null when the invoice is paid so a
 * genuinely re-opened invoice can alert again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('overdue_notified_at')->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('overdue_notified_at');
        });
    }
};
