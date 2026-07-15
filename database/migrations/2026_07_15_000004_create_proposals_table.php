<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 18 — Proposals / quotations. SHARED across companies
     * (REQUIREMENTS.md §2). VAT is optional (DECISIONS.md): vat_rate is a
     * nullable VatRate value, null = "No aplica".
     */
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('proposal_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('description')->nullable();
            // Line items: [{description, qty, unit_price, vat_rate}]
            $table->json('line_items')->nullable();
            $table->decimal('estimated_quantity', 12, 2)->nullable();
            $table->decimal('estimated_total', 14, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('vat_rate', 20)->nullable();      // VatRate, optional
            $table->decimal('vat_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('draft');  // ProposalStatus
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
