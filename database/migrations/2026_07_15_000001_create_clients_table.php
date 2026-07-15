<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 07 — Clients. SHARED across all companies by design
     * (REQUIREMENTS.md §2): no company_id, no tenancy scope. Soft-deleted
     * per the conventions (employees + clients only).
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('nif', 20)->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->string('client_type', 20)->default('company'); // ClientType
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('website')->nullable();
            $table->string('bank_account', 34)->nullable();
            $table->string('payment_terms', 50)->default('net30');
            $table->string('industry', 100)->nullable();
            $table->string('company_size', 50)->nullable();
            $table->string('preferred_contact', 20)->nullable(); // email|phone|mobile|other
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->index('nif');
        });

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('designation', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->timestamps();
        });

        // Client communication timeline (Tab 6). Kept editable (unlike
        // project notes) — this is client relationship management.
        Schema::create('client_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('note'); // call|meeting|email|note
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->dateTime('logged_at');
            $table->timestamps();

            $table->index(['client_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_communications');
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
    }
};
