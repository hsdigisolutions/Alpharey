<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG 4 — the Super-Admin review queue needs to show WHO escalated an expense
 * and any note they left when sending it to review. Both are server-set at
 * send-to-review time (never mass-assignable). Additive + nullable: a null
 * escalated_by / review_note is the normal (never-escalated) state, so every
 * existing row is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('escalated_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable()->after('escalated_by');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropColumn('review_note');
        });
    }
};
