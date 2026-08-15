<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle session media + auto-mileage.
 *
 * vehicle_sessions gains a condition PHOTO (required for a worker) and an
 * optional VOICE note at both take and return — all on the private disk,
 * server-set (never mass-assignable), like the check-in selfie / task-progress
 * photo.
 *
 * vehicle_mileage_histories gains provenance: a return now AUTO-creates an
 * odometer row, tagged source='worker_session' and linked back to the session
 * (with the km driven). recorded_at widens to datetime so the exact return
 * time is kept, not just the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_sessions', function (Blueprint $table): void {
            $table->string('take_photo_path')->nullable()->after('return_notes');
            $table->string('take_voice_note_path')->nullable()->after('take_photo_path');
            $table->unsignedSmallInteger('take_voice_duration')->nullable()->after('take_voice_note_path');
            $table->string('return_photo_path')->nullable()->after('take_voice_duration');
            $table->string('return_voice_note_path')->nullable()->after('return_photo_path');
            $table->unsignedSmallInteger('return_voice_duration')->nullable()->after('return_voice_note_path');
        });

        Schema::table('vehicle_mileage_histories', function (Blueprint $table): void {
            $table->dateTime('recorded_at')->change();
            $table->string('source', 30)->nullable()->after('recorded_at'); // 'worker_session' or null
            $table->foreignId('session_id')->nullable()->after('source')->constrained('vehicle_sessions')->nullOnDelete();
            $table->unsignedInteger('km_driven')->nullable()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_mileage_histories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn(['source', 'km_driven']);
            $table->date('recorded_at')->change();
        });

        Schema::table('vehicle_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'take_photo_path', 'take_voice_note_path', 'take_voice_duration',
                'return_photo_path', 'return_voice_note_path', 'return_voice_duration',
            ]);
        });
    }
};
