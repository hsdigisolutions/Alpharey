<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart expense split — one expense's TOTAL distributed across several
 * categories (Materials 40 / Transport 20 / Labor 30 / Other 10 = 100).
 *
 * An expense is EITHER single-category (its expense_category_id, no split rows)
 * OR multi-split (>=2 rows here, expense_category_id left null). Split amounts
 * are VAT-inclusive and must sum to the expense total. Additive + safe: a new
 * table, no change to existing expenses (which simply have no split rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_splits');
    }
};
