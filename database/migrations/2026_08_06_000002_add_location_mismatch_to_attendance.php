<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            // Set when a worker checks out from a location > 500 m from where
            // they checked in. Null means no GPS fix was available at check-out
            // (or this is a clerk-entered row with no GPS data at all).
            $table->boolean('location_mismatch')->nullable()->after('location_denied');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropColumn('location_mismatch');
        });
    }
};
