<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sujeta a IVA / Taxable" vs "No sujeta o exenta / Non-taxable" — a legal
 * operation classification distinct from the VAT RATE (which VatRate already
 * encodes). Additive + a sensible default of TRUE (taxable) so every existing
 * invoice and expense keeps its current meaning and money untouched. This flag
 * drives a form toggle + list filter only; it never changes VAT calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('is_taxable')->default(true)->after('vat_amount');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->boolean('is_taxable')->default(true)->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('is_taxable');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('is_taxable');
        });
    }
};
