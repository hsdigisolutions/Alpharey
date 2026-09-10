<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Call outcome tracking (2026-09). A call log now records WHETHER the worker was
 * reached — `connected` (answered) vs `no_answer` (attempted, not reached) — so
 * the Call Panel can separate "who was contacted" from "who was tried but not
 * reached", instead of showing one mixed pile.
 *
 * `call_outcome` is nullable (a legacy row predates the field). Existing rows are
 * backfilled to `connected` (client-confirmed 2026-09): every existing log is a
 * real conversation with remarks — an actual contact that was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_call_logs', function (Blueprint $table): void {
            $table->string('call_outcome', 20)->nullable()->after('remarks');
        });

        // Backfill: existing logs are real logged conversations → connected.
        DB::table('employee_call_logs')->whereNull('call_outcome')->update(['call_outcome' => 'connected']);
    }

    public function down(): void
    {
        Schema::table('employee_call_logs', function (Blueprint $table): void {
            $table->dropColumn('call_outcome');
        });
    }
};
