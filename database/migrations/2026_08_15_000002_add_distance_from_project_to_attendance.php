<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Haversine distance (metres) from the worker's check-in GPS to the
     * project they punched into. Set only when the project has coordinates AND
     * the check-in fix is trustworthy (accuracy < 1000 m); null otherwise
     * (unconfigured project or GPS too coarse to verify). Server-set, not
     * mass-assignable — like the check-in coordinates themselves.
     */
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->decimal('distance_from_project', 8, 2)->nullable()->after('location_mismatch');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropColumn('distance_from_project');
        });
    }
};
