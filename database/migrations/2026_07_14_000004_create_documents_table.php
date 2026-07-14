<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unified polymorphic document store (companies now; projects,
     * vehicles… in their phases). Files live under storage/app/private —
     * never web-addressable; every row can carry a Yes/No confirmation
     * flag, expiry, and versions (REQUIREMENTS.md Screens 04/06/16).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('documentable');
            // Owning company for tenancy scoping + compliance rollups
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            // personal|employment|training|medical|custom|company
            $table->string('category', 20);
            $table->string('type_key', 60)->index();
            $table->string('name')->nullable(); // custom label for custom slots
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->boolean('has_flag')->nullable(); // the Yes/No field
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->boolean('is_exempt')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'expiry_date']);
            $table->index(['documentable_type', 'documentable_id', 'type_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
