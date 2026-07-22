<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Worker PWA records on top of a normal attendance row.
 *
 * The existing `check_in` / `check_out` are `string(5)` "H:i" — fine for a
 * clerk typing a timesheet, not enough for a phone punch, which needs the real
 * instant (date + seconds + timezone) to be defensible. So the punch moments
 * are stored as real timestamps ALONGSIDE the H:i strings, which the payroll
 * and grid code keeps reading unchanged.
 *
 * GPS is stored as decimal(10,7) — ~1cm resolution, far finer than any phone
 * delivers, and exact (not float) so a coordinate round-trips unchanged.
 * `accuracy` is the radius in metres the device itself reports; a 5m fix and a
 * 2000m fix look identical without it.
 *
 * `location_denied` exists because the client's decision is that a refused GPS
 * permission must NOT block the punch — it records the punch and flags it, so
 * an admin can see "this one has no location, and here is why".
 *
 * `worker_note` carries the absence reason a worker types on their phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            // Real punch instants (the H:i strings stay authoritative for payroll)
            $table->timestamp('check_in_at')->nullable()->after('check_out');
            $table->timestamp('check_out_at')->nullable()->after('check_in_at');

            $table->decimal('check_in_lat', 10, 7)->nullable()->after('check_out_at');
            $table->decimal('check_in_lng', 10, 7)->nullable()->after('check_in_lat');
            $table->decimal('check_in_accuracy', 8, 2)->nullable()->after('check_in_lng');

            $table->decimal('check_out_lat', 10, 7)->nullable()->after('check_in_accuracy');
            $table->decimal('check_out_lng', 10, 7)->nullable()->after('check_out_lat');
            $table->decimal('check_out_accuracy', 8, 2)->nullable()->after('check_out_lng');

            // Selfie taken at check-in — private disk, same handling as documents
            $table->string('check_in_photo_path')->nullable()->after('check_out_accuracy');

            // Punch recorded without a location (permission refused / no fix)
            $table->boolean('location_denied')->default(false)->after('check_in_photo_path');

            // The worker's own words: absence reason, or a note with the punch
            $table->text('worker_note')->nullable()->after('location_denied');

            // Where the row came from, so an admin can tell a phone punch from
            // a clerk's timesheet entry at a glance.
            $table->string('source', 20)->default('admin')->after('worker_note');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropColumn([
                'check_in_at', 'check_out_at',
                'check_in_lat', 'check_in_lng', 'check_in_accuracy',
                'check_out_lat', 'check_out_lng', 'check_out_accuracy',
                'check_in_photo_path', 'location_denied', 'worker_note', 'source',
            ]);
        });
    }
};
