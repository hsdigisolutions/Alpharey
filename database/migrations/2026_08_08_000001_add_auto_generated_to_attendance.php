<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags an absence created by the nightly auto-absent sweep, so the calendar can
 * shade it lighter than a manually-marked absence and reports can count the two
 * separately. Server-only — never mass assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->boolean('is_auto_generated')->default(false)->after('override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('is_auto_generated');
        });
    }
};
