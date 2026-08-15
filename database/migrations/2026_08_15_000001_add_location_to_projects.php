<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worker location verification — a project's own coordinates + geofence.
     * When a worker checks in, the distance from their GPS to the nearest
     * assigned project's coordinates is measured (evidence, never a gate).
     * geofence_radius (metres, default 500) is the on-site boundary; a null
     * lat/lng means the project is unconfigured and no distance is computed.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('geofence_radius')->default(500)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'geofence_radius']);
        });
    }
};
