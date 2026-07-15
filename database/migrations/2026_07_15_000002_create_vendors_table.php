<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 20 — Vendors. SHARED across companies (REQUIREMENTS.md §2):
     * no tenancy scope. No soft delete (not in the soft-delete list).
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('nif', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('area', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('bank_account', 34)->nullable();
            $table->string('payment_terms', 50)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('position', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_payment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('days')->default(30);
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->unsignedInteger('discount_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_terms');
        Schema::dropIfExists('vendor_contacts');
        Schema::dropIfExists('vendors');
    }
};
