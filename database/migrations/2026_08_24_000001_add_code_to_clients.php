<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change 6 — clients gain an editable, auto-generated code (CLI-001, …).
 * Neither the new CRM nor the legacy VertoCRM ever had a client code, so this
 * starts a fresh sequence. Additive + backfilled; existing clients keep their
 * id and simply receive a sequential code (ordered by id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('code', 30)->nullable()->after('id');
        });

        // Backfill a sequential CLI-#### for every existing client (by id), via
        // the query builder so no model events / audit rows fire.
        $n = 0;
        foreach (DB::table('clients')->orderBy('id')->pluck('id') as $id) {
            $n++;
            DB::table('clients')->where('id', $id)->update([
                'code' => 'CLI-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            ]);
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
