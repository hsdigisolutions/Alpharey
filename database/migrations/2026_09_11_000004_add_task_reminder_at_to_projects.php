<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadence guard for the "task-based project worked but no task progress logged"
 * nudge — the daily notifications:scan sets it so a project is nudged again only
 * after the cadence window, mirroring the invoice_reminders cadence. Server-set,
 * never fillable. Additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('task_reminder_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('task_reminder_at');
        });
    }
};
