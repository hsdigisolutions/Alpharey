<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only. The application enforces immutability at the model level;
     * in production the app DB user is additionally denied UPDATE/DELETE on
     * this table at the MySQL grant level (SECURITY.md §6).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // No FK on user_id: audit rows must outlive the user record.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('action', 30);
            $table->string('module', 50)->nullable();
            $table->string('entity_name')->nullable();
            $table->string('model_type')->nullable();
            // varchar(36) so uuid-keyed models (documents) fit alongside bigints
            $table->string('model_id', 36)->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_url', 2048)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['company_id', 'created_at']);
            $table->index(['model_type', 'model_id']);
            $table->index(['action', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
