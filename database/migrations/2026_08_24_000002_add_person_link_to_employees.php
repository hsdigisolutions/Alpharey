<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Change 2 — transfer that KEEPS history. Two additive columns:
 *  - person_uuid: links every employee record of the SAME person across
 *    companies (each transfer creates a new record sharing this uuid), so the
 *    Employment History tab can list all their company stints.
 *  - transferred_out_at: stamped on the OLD record when a transfer supersedes
 *    it with a new record in another company. A record with this set is in the
 *    distinct "Transferred" state (kept, read-only, excluded from dropdowns).
 *
 * Backfill: give every existing employee a unique person_uuid so single-stint
 * workers also resolve cleanly in the history query. Additive + safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->uuid('person_uuid')->nullable()->after('id')->index();
            $table->timestamp('transferred_out_at')->nullable()->after('transferred_at');
        });

        foreach (DB::table('employees')->whereNull('person_uuid')->pluck('id') as $id) {
            DB::table('employees')->where('id', $id)->update(['person_uuid' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['person_uuid', 'transferred_out_at']);
        });
    }
};
