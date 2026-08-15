<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-side people who handle a SPECIFIC project. One client can run several
 * projects, each with its own representatives — so contacts live on the project,
 * not the client. Company-owned (BelongsToCompany). Simple CRUD, no encryption:
 * these are ordinary contact details, not per-employee pay data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 20)->default('other'); // App\Enums\ProjectContactRole
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contacts');
    }
};
